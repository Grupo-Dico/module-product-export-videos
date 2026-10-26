#!/usr/bin/env bash
set -euo pipefail

# Run only the project's quality tools; this container has no laboratory mount.
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
runtime_key="$(shasum -a 256 "$project_root/dev/php83/Dockerfile" | awk '{print substr($1,1,16)}')"
runtime_image="dico-tooling-php83:$runtime_key"
if ! docker image inspect "$runtime_image" >/dev/null 2>&1; then
  docker build --tag "$runtime_image" "$project_root/dev/php83" >&2
fi
exec docker run --rm \
  --user "$(id -u):$(id -g)" \
  --env COMPOSER_HOME=/tmp/dico-composer \
  --mount "type=bind,source=${project_root},target=/workspace" \
  --workdir /workspace \
  --entrypoint composer \
  "$runtime_image" "$@"
