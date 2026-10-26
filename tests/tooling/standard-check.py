#!/usr/bin/env python3
"""Verifica el estándar mínimo de un módulo LeanCommerce: nombres, arquitectura, seguridad y entregables.

Uso:
  standard-check.py --root ../..            nivel de tarea (forma parte de `check`)
  standard-check.py --root ../.. --release  nivel de versión: además exige los entregables

Los errores fallan; los avisos se reportan. No requiere Magento.

Excepciones por proyecto (módulos existentes): tests/tooling/standard.json
  {"excepciones": {"estructura": "motivo", "nombre-paquete": "motivo", "nombre-modulo": "motivo"}}
Cada excepción convierte esa regla en aviso y debe llevar motivo.
Gestionado por LeanCommerceMX/engineering-standards.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

if sys.version_info < (3, 9):
    sys.exit('Se requiere Python 3.9 o superior.')

errors: list[str] = []
warnings: list[str] = []
exceptions: dict[str, str] = {}


def violation(rule: str, message: str) -> None:
    """Error, salvo excepción declarada para un módulo existente."""
    if rule in exceptions:
        warnings.append(f"[excepción {rule}: {exceptions[rule]}] {message}")
    else:
        errors.append(message)

MODULE_RE = re.compile(r"^LeanCommerce_[A-Z][A-Za-z0-9]+$")
PACKAGE_RE = re.compile(r"^[a-z0-9-]+/module-[a-z0-9-]+$")
SECRET_RES = [
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "llave privada"),
    (re.compile(r"AKIA[0-9A-Z]{16}"), "llave de AWS"),
    (re.compile(r"AIza[0-9A-Za-z_\-]{35}"), "llave de Google API"),
    (re.compile(r"(?i)(password|passwd|secret|api_key|access_token)\s*[=:]\s*['\"][^'\"\s]{8,}['\"]"), "credencial literal"),
]


def kebab(name: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "-", name).lower()


def candidate_files(root: Path, release: bool) -> list[Path]:
    """Mismo candidato que package-check: árbol de trabajo (tarea) o HEAD exacto con árbol limpio (versión)."""
    def git(*args: str, env: dict | None = None, cwd: Path | str = root) -> str:
        return subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True,
                              text=True, env=env).stdout

    try:
        toplevel = git("rev-parse", "--show-toplevel").strip()
    except (subprocess.CalledProcessError, FileNotFoundError):
        errors.append("no es un repositorio git: ejecuta `git init -b main` (no hace falta commit)")
        return []
    prefix = git("rev-parse", "--show-prefix").strip().rstrip("/")
    if release:
        if git("status", "--porcelain", "--", ".").strip():
            errors.append("una versión se verifica con el árbol limpio: hay cambios sin commit")
        tree = git("rev-parse", "HEAD^{tree}").strip()
    else:
        # Seed the temporary index with tracked entries before applying worktree changes.
        # An empty index would drop tracked files that now match .gitignore.
        entries = subprocess.run(['git', 'ls-files', '--stage', '-z'], cwd=toplevel,
                                 check=True, capture_output=True).stdout
        with tempfile.TemporaryDirectory() as tmp:
            env = {**os.environ, "GIT_INDEX_FILE": os.path.join(tmp, "index")}
            subprocess.run(['git', 'update-index', '-z', '--index-info'], cwd=toplevel,
                           input=entries, check=True, capture_output=True, env=env)
            subprocess.run(["git", "add", "-A"], cwd=toplevel, check=True, capture_output=True, env=env)
            tree = git("write-tree", env=env, cwd=toplevel).strip()
    spec = f"{tree}:{prefix}" if prefix else tree
    names = git("ls-tree", "-r", "--name-only", "-z", spec, cwd=toplevel).split("\0")
    return [root / n for n in names if n]


def modules(root: Path, composer: dict) -> list[tuple[str, Path]]:
    found = []
    registrations = composer.get("autoload", {}).get("files", [])
    if not registrations:
        errors.append("composer.json no registra ningún módulo (autoload.files con registration.php)")
    for reg in registrations:
        path = root / reg
        if not path.is_file():
            errors.append(f"registration declarado no existe: {reg}")
            continue
        m = re.search(r"ComponentRegistrar::MODULE\s*,\s*'([A-Za-z0-9_]+)'", path.read_text())
        if not m:
            errors.append(f"sin nombre de módulo en {reg}")
            continue
        found.append((m.group(1), path.parent))
    return found


def check_names(root: Path, composer: dict, mods: list[tuple[str, Path]]) -> None:
    name = composer.get("name", "")
    if not PACKAGE_RE.match(name):
        violation("nombre-paquete", f"paquete '{name}' no cumple <propietario>/module-<nombre-kebab>")
    if composer.get("type") != "magento2-module":
        errors.append("composer.json: type debe ser magento2-module")
    if not composer.get("license"):
        errors.append("composer.json: falta license")
    for module, path in mods:
        if not MODULE_RE.match(module):
            violation("nombre-modulo", f"módulo '{module}' no cumple LeanCommerce_<PascalCase>")
            continue
        short = module.split("_", 1)[1]
        if path.parts[-2:] != ("LeanCommerce", short):
            violation("estructura", f"{module} debe vivir en src/LeanCommerce/{short}/ (está en {path.relative_to(root)})")
        xml = path / "etc" / "module.xml"
        if not xml.is_file() or f'name="{module}"' not in xml.read_text():
            errors.append(f"etc/module.xml de {module} no declara name=\"{module}\"")
    if len(mods) == 1 and PACKAGE_RE.match(name):
        expected = "module-" + kebab(mods[0][0].split("_", 1)[1])
        if name.split("/", 1)[1] != expected:
            violation("nombre-paquete", f"paquete '{name}' no coincide con el módulo: se espera <propietario>/{expected}")


def check_module_files(root: Path, mods: list[tuple[str, Path]], files: list[Path]) -> None:
    candidates = set(files)
    for module, path in mods:
        for php in sorted(x for x in path.rglob("*.php") if x in candidates):
            text = php.read_text(errors="ignore")
            rel = php.relative_to(root)
            if "ObjectManager::getInstance" in text:
                errors.append(f"{rel}: ObjectManager::getInstance prohibido")
            if re.search(r"\beval\s*\(", text):
                errors.append(f"{rel}: eval prohibido")
            if "/Domain/" in str(php) and re.search(r"\bMagento\\", text):
                errors.append(f"{rel}: Domain/ no puede depender de Magento")
            if "/Controller/Adminhtml/" in str(php) and re.search(r"\bclass\s+\w+", text) \
                    and "abstract class" not in text and "ADMIN_RESOURCE" not in text:
                errors.append(f"{rel}: controlador de administración sin ADMIN_RESOURCE")
        acl = path / "etc" / "acl.xml"
        if acl.is_file():
            for rid in re.findall(r'<resource\s+id="([^"]+)"', acl.read_text()):
                if not rid.startswith(("Magento_", "LeanCommerce_")):
                    errors.append(f"{module}: recurso ACL '{rid}' debe empezar con LeanCommerce_")
        schema = path / "etc" / "db_schema.xml"
        if schema.is_file():
            for table in re.findall(r'<table\s+name="([^"]+)"', schema.read_text()):
                if not table.startswith("lc_"):
                    warnings.append(f"{module}: tabla '{table}' sin prefijo lc_ (sólo aceptable en módulos existentes)")
        system = path / "etc" / "adminhtml" / "system.xml"
        if system.is_file():
            for section in re.findall(r'<section\s+id="([^"]+)"', system.read_text()):
                if not section.startswith("lc_"):
                    warnings.append(f"{module}: sección de configuración '{section}' sin prefijo lc_")
        webapi = path / "etc" / "webapi.xml"
        if webapi.is_file():
            for url in re.findall(r'<route\s+url="([^"]+)"[^>]*>(?:(?!</route>).)*ref="anonymous"', webapi.read_text(), re.S):
                guide = root / "docs" / "guia-api-y-compatibilidad.md"
                if not guide.is_file() or url not in guide.read_text():
                    errors.append(f"{module}: ruta anónima {url} sin justificación en docs/guia-api-y-compatibilidad.md")


def check_secrets(files: list[Path], root: Path) -> None:
    for f in files:
        if f.suffix in {".png", ".jpg", ".gif", ".pdf", ".zip", ".woff", ".woff2"} or not f.is_file():
            continue
        if "/tests/tooling/" in str(f) and f.name == "standard-check.py":
            continue
        try:
            text = f.read_text(errors="ignore")
        except OSError:
            continue
        for regex, label in SECRET_RES:
            if regex.search(text):
                errors.append(f"{f.relative_to(root)}: posible {label} en el repo")


def check_release(root: Path, mods: list[tuple[str, Path]]) -> None:
    for required in ("README.md", "CHANGELOG.md", "docs/manual-de-usuario.md"):
        p = root / required
        if not p.is_file():
            errors.append(f"entregable faltante: {required}")
        elif "<!-- completar" in p.read_text():
            errors.append(f"{required} todavía tiene secciones por completar")
    if not (root / "LICENSE.md").is_file() and not (root / "LICENSE").is_file():
        errors.append("entregable faltante: LICENSE.md")
    if not any((root / "tests" / "unit").rglob("*Test.php")):
        errors.append("sin pruebas unitarias en tests/unit")
    for module, path in mods:
        uses_text = any("__(" in p.read_text(errors="ignore") for p in path.rglob("*.p*") if p.suffix in {".php", ".phtml"})
        if uses_text and not (path / "i18n" / "es_MX.csv").is_file():
            errors.append(f"{module}: usa __() pero falta i18n/es_MX.csv")
    changelog = root / "CHANGELOG.md"
    if changelog.is_file():
        m = re.search(r"^##\s+v?(\d+\.\d+\.\d+)", changelog.read_text(), re.M)
        if not m:
            errors.append("CHANGELOG.md sin una versión publicada (## X.Y.Z)")
        else:
            try:
                top = subprocess.run(["git", "rev-parse", "--show-toplevel"], cwd=root, check=True,
                                     capture_output=True, text=True).stdout.strip()
                if Path(top).resolve() == root.resolve():
                    tag = subprocess.run(["git", "describe", "--tags", "--exact-match"], cwd=root,
                                         capture_output=True, text=True).stdout.strip()
                    if tag and tag.lstrip("v") != m.group(1):
                        errors.append(f"tag {tag} no coincide con la versión del CHANGELOG ({m.group(1)})")
            except (subprocess.CalledProcessError, FileNotFoundError):
                pass


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=".")
    parser.add_argument("--release", action="store_true")
    args = parser.parse_args()
    root = Path(args.root).resolve()
    config = root / "tests" / "tooling" / "standard.json"
    if config.is_file():
        declared = json.loads(config.read_text()).get("excepciones", {})
        for rule, reason in declared.items():
            if not str(reason).strip():
                errors.append(f"standard.json: la excepción '{rule}' no tiene motivo")
            exceptions[rule] = str(reason)
    composer = json.loads((root / "composer.json").read_text())
    mods = modules(root, composer)
    check_names(root, composer, mods)
    files = candidate_files(root, args.release)
    check_module_files(root, mods, files)
    check_secrets(files, root)
    if args.release:
        check_release(root, mods)
    for w in warnings:
        print(f"AVISO: {w}", file=sys.stderr)
    if errors:
        for e in errors:
            print(f"FAIL: {e}", file=sys.stderr)
        sys.exit(1)
    level = "versión" if args.release else "tarea"
    print(f"PASS: estándar ({level}) en {len(mods)} módulo(s)")


if __name__ == "__main__":
    main()
