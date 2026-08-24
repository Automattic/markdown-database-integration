#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BINARY="${TMPDIR:-/tmp}/mdi-native-engine-$$"
trap 'rm -f "$BINARY"' EXIT

go build -C "$ROOT/native-engine" -o "$BINARY" .
php "$ROOT/tests/integration-native-mysql-sidecar.php" "$BINARY"
