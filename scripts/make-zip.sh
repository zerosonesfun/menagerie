#!/usr/bin/env bash
# Create a distributable zip of the plugin folder excluding node_modules (for manual releases).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PARENT="$(dirname "$PLUGIN_ROOT")"
NAME="$(basename "$PLUGIN_ROOT")"
OUT="${PARENT}/${NAME}-release.zip"

cd "$PARENT"
rm -f "$OUT"
zip -r "$OUT" "$NAME" \
	-x "${NAME}/node_modules/*" \
	-x "${NAME}/node_modules" \
	-x "*.DS_Store"

echo "Created: $OUT"
