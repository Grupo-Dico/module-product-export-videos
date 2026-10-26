#!/usr/bin/env python3
"""Arma la entrega versionada desde el commit exacto (HEAD, árbol limpio).

Genera en dist/:
  <nombre>-<versión>.zip            paquete instalable (git archive, respeta export-ignore)
  <nombre>-<versión>-docs.zip       documentación pública: README, CHANGELOG, LICENSE y docs/
  <nombre>-<versión>-manifest.json  versión, commit y SHA-256 de cada ZIP y de cada archivo del paquete

El manifiesto permite comprobar después que lo instalado coincide con lo construido.
Gestionado por LeanCommerceMX/engineering-standards.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import re
import subprocess
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

if sys.version_info < (3, 9):
    sys.exit('Se requiere Python 3.9 o superior.')

DOC_FILES = ('README.md', 'CHANGELOG.md', 'LICENSE.md', 'LICENSE')


def git(root: Path, *args: str, binary: bool = False):
    out = subprocess.run(['git', *args], cwd=root, check=True, capture_output=True).stdout
    return out if binary else out.decode().strip()


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', default='.')
    root = Path(parser.parse_args().root).resolve()

    if git(root, 'status', '--porcelain', '--', '.'):
        sys.exit('FAIL: la entrega se arma con el árbol limpio; hay cambios sin commit')
    prefix = git(root, 'rev-parse', '--show-prefix').rstrip('/')
    toplevel = Path(git(root, 'rev-parse', '--show-toplevel'))
    commit = git(root, 'rev-parse', 'HEAD')
    tree = f'HEAD:{prefix}' if prefix else 'HEAD'

    composer = json.loads((root / 'composer.json').read_text())
    name = composer['name'].split('/', 1)[1]
    match = re.search(r'^##\s+v?(\d+\.\d+\.\d+)', (root / 'CHANGELOG.md').read_text(), re.M)
    if not match:
        sys.exit('FAIL: CHANGELOG.md sin versión publicada (## X.Y.Z)')
    version = match.group(1)

    dist = root / 'dist'
    dist.mkdir(exist_ok=True)
    base = f'{name}-{version}'

    package_zip = git(toplevel, 'archive', '--format=zip', tree, binary=True)
    (dist / f'{base}.zip').write_bytes(package_zip)
    with zipfile.ZipFile(io.BytesIO(package_zip)) as archive:
        contents = {i.filename: sha256(archive.read(i)) for i in archive.infolist() if not i.is_dir()}

    files = git(toplevel, 'ls-tree', '-r', '--name-only', tree).splitlines()
    docs = [f for f in files if f in DOC_FILES or f.startswith('docs/')]
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
        for doc in sorted(docs):
            archive.writestr(doc, git(toplevel, 'show', f'{tree}/{doc}' if prefix else f'HEAD:{doc}', binary=True))
    (dist / f'{base}-docs.zip').write_bytes(buffer.getvalue())

    manifest = {
        'paquete': composer['name'],
        'version': version,
        'commit': commit,
        'generado': datetime.now(timezone.utc).isoformat(timespec='seconds'),
        'artefactos': {
            f'{base}.zip': sha256(package_zip),
            f'{base}-docs.zip': sha256(buffer.getvalue()),
        },
        'contenido_paquete': contents,
    }
    (dist / f'{base}-manifest.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n')
    print(f'PASS: entrega {base} en dist/ ({len(contents)} archivos de paquete, {len(docs)} de documentación)')


if __name__ == '__main__':
    main()
