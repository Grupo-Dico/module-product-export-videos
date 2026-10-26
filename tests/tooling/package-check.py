#!/usr/bin/env python3
"""Verifica el contenido REAL de la distribución (git archive del candidato).

Comprueba que el paquete no incluya pruebas, documentación interna, demos,
herramientas ni secretos, y que cada módulo registrado tenga su module.xml.
No requiere Magento ni credenciales. Gestionado por LeanCommerceMX/engineering-standards.
"""
from __future__ import annotations

import argparse
import io
import json
import os
import re
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path, PurePosixPath

if sys.version_info < (3, 9):
    sys.exit('Se requiere Python 3.9 o superior.')

# Prohibidos en la raíz del paquete.
FORBIDDEN_DIRS = {
    'tests', 'internal', 'dev', 'docs', 'tools', '.github', '.engineering-standards', 'private',
    'vendor', 'node_modules', 'generated', 'var', 'evidence', 'dist',
}
# Prohibidos a cualquier profundidad (pruebas, evidencia y dependencias dentro de un módulo).
FORBIDDEN_ANY_DEPTH = {'tests', 'Test', 'internal', 'evidence', 'vendor', 'node_modules'}
FORBIDDEN_FILES = {
    'auth.json', 'env.php', '.env', 'phpunit.xml', 'phpunit.xml.dist',
    'phpcs.xml.dist', 'phpstan.neon.dist', 'AGENTS.md', 'CLAUDE.md',
    '.git-blame-ignore-revs', 'SOURCE-SHA256.txt',
}
FORBIDDEN_SUFFIXES = ('.sql', '.sql.gz', '.log', '.pem', '.key', '.p12')
REQUIRED_FILES = {'composer.json', 'README.md', 'CHANGELOG.md'}


def fail(message: str) -> None:
    print(f'FAIL: {message}', file=sys.stderr)
    sys.exit(1)


def candidate_tree(root: Path, ref: str | None) -> tuple[str, str]:
    """Árbol a verificar y su toplevel.

    ref=None: candidato = árbol de trabajo actual (archivos rastreados y nuevos no ignorados),
    sin necesidad de commit. ref='HEAD': el commit exacto (publicación de versión).
    """
    def git(*args: str, env: dict | None = None) -> str:
        return subprocess.run(['git', *args], cwd=root, check=True, capture_output=True,
                              text=True, env=env).stdout.strip()

    try:
        toplevel = git('rev-parse', '--show-toplevel')
    except (subprocess.CalledProcessError, FileNotFoundError):
        fail('no es un repositorio git: ejecuta `git init -b main` (no hace falta commit)')
    prefix = git('rev-parse', '--show-prefix').rstrip('/')
    if ref:
        tree = git('rev-parse', f'{ref}^{{tree}}')
    else:
        # Seed the temporary index with tracked entries before applying worktree changes.
        # An empty index would drop tracked files that now match .gitignore.
        entries = subprocess.run(['git', 'ls-files', '--stage', '-z'], cwd=toplevel,
                                 check=True, capture_output=True).stdout
        with tempfile.TemporaryDirectory() as tmp:
            env = {**os.environ, 'GIT_INDEX_FILE': os.path.join(tmp, 'index')}
            subprocess.run(['git', 'update-index', '-z', '--index-info'], cwd=toplevel,
                           input=entries, check=True, capture_output=True, env=env)
            subprocess.run(['git', 'add', '-A'], cwd=toplevel, check=True, capture_output=True, env=env)
            tree = subprocess.run(['git', 'write-tree'], cwd=toplevel, check=True, capture_output=True,
                                  text=True, env=env).stdout.strip()
    return (f'{tree}:{prefix}' if prefix else tree), toplevel


def archive_members(root: Path, ref: str | None) -> dict:
    tree, toplevel = candidate_tree(root, ref)
    raw = subprocess.run(['git', 'archive', '--format=tar', tree], cwd=toplevel,
                         check=True, capture_output=True).stdout
    members = {}
    with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
        for member in archive.getmembers():
            name = member.name.removeprefix('./').rstrip('/')
            if name and member.isfile():
                members[name] = archive.extractfile(member).read()
    return members


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', default='.', help='Raíz del proyecto (donde está composer.json).')
    parser.add_argument('--ref', default=None, help='Commit a verificar (p. ej. HEAD). Por omisión, el árbol de trabajo.')
    args = parser.parse_args()
    root = Path(args.root).resolve()
    composer = json.loads((root / 'composer.json').read_text())
    members = archive_members(root, args.ref)
    if not members:
        fail('el paquete candidato está vacío')

    for name in members:
        parts = PurePosixPath(name).parts
        base = PurePosixPath(name).name
        if name.startswith('/') or '..' in parts:
            fail(f'ruta insegura en el paquete: {name}')
        if parts[0] in FORBIDDEN_DIRS:
            fail(f'contenido excluido dentro del paquete: {name} (agrega "/{parts[0]} export-ignore" a .gitattributes)')
        nested = [d for d in parts[1:-1] if d in FORBIDDEN_ANY_DEPTH]
        if nested:
            fail(f'contenido excluido dentro del paquete: {name} (directorio "{nested[0]}" anidado; usa tests/ en la raíz)')
        if base in FORBIDDEN_FILES or base.startswith('.env.') or base.endswith(FORBIDDEN_SUFFIXES):
            fail(f'archivo prohibido dentro del paquete: {name}')

    missing = REQUIRED_FILES - set(members)
    if missing:
        fail(f'faltan archivos obligatorios en el paquete: {sorted(missing)}')
    if 'LICENSE.md' not in members and 'LICENSE' not in members:
        fail('el paquete no incluye LICENSE.md')

    registrations = composer.get('autoload', {}).get('files', [])
    if not registrations:
        fail('composer.json no registra ningún módulo (autoload.files con registration.php)')
    for registration in registrations:
        if registration not in members:
            fail(f'registration declarado en autoload.files no está en el paquete: {registration}')
        module_xml = str(PurePosixPath(registration).parent / 'etc' / 'module.xml')
        if module_xml not in members:
            fail(f'módulo sin etc/module.xml: {module_xml}')
        match = re.search(rb"ComponentRegistrar::MODULE\s*,\s*'([A-Za-z0-9_]+)'", members[registration])
        if not match:
            fail(f'no se encontró el nombre del módulo en {registration}')

    print(f'PASS: paquete con {len(members)} archivos, {len(registrations)} módulo(s) registrado(s), sin contenido excluido')


if __name__ == '__main__':
    main()
