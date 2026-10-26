#!/usr/bin/env bash
# Single entry point. Python owns locking, immutable candidates, evidence and cleanup.
set -euo pipefail
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
exec python3 "$project_root/tests/integration/runner.py" "$@"
