#!/usr/bin/env bash
# Create a distributable zip for manual / GitHub releases.
#
# - Zip filename includes the version: menagerie-2.0.0.zip
# - Folder inside the zip is always "menagerie" (never versioned) so WordPress
#   updates replace the same plugin directory and keep working.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SLUG="menagerie"

VERSION="$(
	grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_ROOT/menagerie.php" \
		| head -1 \
		| sed -E 's/.*Version:[[:space:]]*//' \
		| tr -d '[:space:]'
)"
if [[ -z "$VERSION" ]]; then
	echo "Could not read Version from menagerie.php" >&2
	exit 1
fi

# Written inside the plugin root (excluded from the archive via *.zip).
OUT="${PLUGIN_ROOT}/${SLUG}-${VERSION}.zip"
STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

mkdir -p "${STAGE}/${SLUG}"
# Prefer rsync when available; fall back to tar for portability.
if command -v rsync >/dev/null 2>&1; then
	rsync -a \
		--exclude 'node_modules' \
		--exclude '.git' \
		--exclude '.DS_Store' \
		--exclude '*.zip' \
		--exclude 'package-lock.json' \
		--exclude 'src' \
		"${PLUGIN_ROOT}/" "${STAGE}/${SLUG}/"
else
	tar -C "$PLUGIN_ROOT" \
		--exclude=node_modules \
		--exclude=.git \
		--exclude=.DS_Store \
		--exclude=package-lock.json \
		--exclude=src \
		-cf - . | tar -C "${STAGE}/${SLUG}" -xf -
fi

rm -f "$OUT"
(
	cd "$STAGE"
	zip -r "$OUT" "$SLUG" -x "*.DS_Store"
)

echo "Created: $OUT"
echo "Contains folder: ${SLUG}/ (fixed name for WordPress updates)"
