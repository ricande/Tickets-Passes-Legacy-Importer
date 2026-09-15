#!/usr/bin/env bash
# Builds a WordPress plugin ZIP (no tests, no .git).
# Usage: bash scripts/build-plugin-zip.sh
# Optional: TPFWLI_ZIP_OUT=/tmp/plugin.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tickets-passes-legacy-importer"
VERSION="$(php -r '
$s = file_get_contents($argv[1]);
if (!preg_match("/^\s*\*\s*Version:\s*(\S+)/m", $s, $m)) { fwrite(STDERR, "no Version header\n"); exit(1); }
echo $m[1];
' "$ROOT/tickets-passes-legacy-importer.php")"

OUT="${TPFWLI_ZIP_OUT:-$ROOT/dist/${SLUG}-${VERSION}.zip}"
STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

rsync -a \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude '.cursor/' \
	--exclude 'tests/' \
	--exclude 'scripts/' \
	--exclude 'vendor/' \
	--exclude 'node_modules/' \
	--exclude 'dist/' \
	--exclude '.gitignore' \
	--exclude 'README.md' \
	--exclude 'phpunit.xml' \
	--exclude '*.log' \
	--exclude '.phpunit.cache/' \
	--exclude 'docs/' \
	"$ROOT/" "$DEST/"

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" "$SLUG")

echo "$OUT"
echo "version=${VERSION} slug=${SLUG}"
if command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$OUT"
fi
if command -v stat >/dev/null 2>&1; then
	stat -c 'size=%s' "$OUT"
fi
