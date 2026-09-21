#!/usr/bin/env bash
# Build (unless TPFWLI_ZIP_OUT already exists and TPFWLI_SKIP_BUILD=1) and
# verify the WordPress plugin ZIP contract.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tickets-passes-legacy-importer"

HEADER_VERSION="$(php -r '
$s = file_get_contents($argv[1]);
if (!preg_match("/^\s*\*\s*Version:\s*(\S+)/m", $s, $m)) { fwrite(STDERR, "no Version header\n"); exit(1); }
echo $m[1];
' "$ROOT/tickets-passes-legacy-importer.php")"
CONST_VERSION="$(php -r '
$s = file_get_contents($argv[1]);
if (!preg_match("/define\(\x27TPFWLI_VERSION\x27,\s*\x27([^\x27]+)\x27/", $s, $m)) { fwrite(STDERR, "no TPFWLI_VERSION\n"); exit(1); }
echo $m[1];
' "$ROOT/tickets-passes-legacy-importer.php")"
STABLE="$(php -r '
$s = file_get_contents($argv[1]);
if (!preg_match("/^Stable tag:\s*(\S+)/m", $s, $m)) { fwrite(STDERR, "no Stable tag\n"); exit(1); }
echo $m[1];
' "$ROOT/readme.txt")"

if [[ "$HEADER_VERSION" != "$CONST_VERSION" || "$HEADER_VERSION" != "$STABLE" ]]; then
	echo "version mismatch header=${HEADER_VERSION} const=${CONST_VERSION} stable=${STABLE}" >&2
	exit 1
fi

if [[ "${TPFWLI_SKIP_BUILD:-0}" != 1 ]]; then
	bash "$ROOT/scripts/build-plugin-zip.sh"
fi
ZIP="${TPFWLI_ZIP_OUT:-$ROOT/dist/${SLUG}-${HEADER_VERSION}.zip}"
if [[ ! -f "$ZIP" ]]; then
	echo "ZIP missing: $ZIP" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT
unzip -q "$ZIP" -d "$STAGE"

mapfile -t TOP < <(find "$STAGE" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)
if [[ "${#TOP[@]}" -ne 1 || "${TOP[0]}" != "$SLUG" ]]; then
	echo "expected exactly one top folder ${SLUG}/, got: ${TOP[*]-}" >&2
	exit 1
fi
DEST="$STAGE/$SLUG"

need=(
	tickets-passes-legacy-importer.php
	readme.txt
	LICENSE
	uninstall.php
	includes/class-tpfwli-plugin.php
	includes/class-tpfwli-tpfw-adapter.php
)
for rel in "${need[@]}"; do
	if [[ ! -e "$DEST/$rel" ]]; then
		echo "ZIP missing $rel" >&2
		exit 1
	fi
done

forbidden=(.git .github tests scripts docs dist vendor node_modules README.md phpunit.xml .gitignore .cursor)
for rel in "${forbidden[@]}"; do
	if [[ -e "$DEST/$rel" ]]; then
		echo "ZIP must not contain $rel" >&2
		exit 1
	fi
done

if grep -R -n -E -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' -e 'AKIA[0-9A-Z]{16}' -e 'ghp_[A-Za-z0-9]{20,}' -e 'github_pat_[A-Za-z0-9_]{20,}' "$DEST" >/dev/null; then
	echo "ZIP appears to contain a secret" >&2
	exit 1
fi
if grep -R -n -E -e '/home/ricande' -e '/var/www/woocommerce' -e '192\.168\.122\.' "$DEST" >/dev/null; then
	echo "ZIP contains a local path or VM address" >&2
	exit 1
fi

ZIP_HEADER="$(php -r '
$s = file_get_contents($argv[1]);
preg_match("/^\s*\*\s*Version:\s*(\S+)/m", $s, $m);
echo $m[1] ?? "";
' "$DEST/tickets-passes-legacy-importer.php")"
ZIP_CONST="$(php -r '
$s = file_get_contents($argv[1]);
preg_match("/define\(\x27TPFWLI_VERSION\x27,\s*\x27([^\x27]+)\x27/", $s, $m);
echo $m[1] ?? "";
' "$DEST/tickets-passes-legacy-importer.php")"
ZIP_STABLE="$(php -r '
$s = file_get_contents($argv[1]);
preg_match("/^Stable tag:\s*(\S+)/m", $s, $m);
echo $m[1] ?? "";
' "$DEST/readme.txt")"
if [[ "$ZIP_HEADER" != "$HEADER_VERSION" || "$ZIP_CONST" != "$HEADER_VERSION" || "$ZIP_STABLE" != "$HEADER_VERSION" ]]; then
	echo "ZIP versions mismatch header=${ZIP_HEADER} const=${ZIP_CONST} stable=${ZIP_STABLE} expected=${HEADER_VERSION}" >&2
	exit 1
fi
if ! grep -q "^= ${HEADER_VERSION} =" "$DEST/readme.txt"; then
	echo "ZIP readme.txt is missing changelog heading = ${HEADER_VERSION} =" >&2
	exit 1
fi

lint_fail=0
while IFS= read -r -d '' file; do
	if ! php -l "$file" >/dev/null; then
		lint_fail=1
	fi
done < <(find "$DEST" -name '*.php' -type f -print0)
if [[ "$lint_fail" -ne 0 ]]; then
	exit 1
fi

BYTES="$(stat -c '%s' "$ZIP")"
ENTRIES="$(zipinfo -1 "$ZIP" | wc -l)"
HASH="$(sha256sum "$ZIP" | awk '{print $1}')"
echo "package_contract_ok zip=${ZIP} version=${HEADER_VERSION} bytes=${BYTES} entries=${ENTRIES} sha256=${HASH}"
