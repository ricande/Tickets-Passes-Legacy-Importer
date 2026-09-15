#!/usr/bin/env bash
# Downloads PHPUnit 11.5.42 into tests/phpunit.phar when missing or checksum-stale.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHAR="$ROOT/tests/phpunit.phar"
VERSION="11.5.42"
URL="https://phar.phpunit.de/phpunit-${VERSION}.phar"
SHA256="894c651ee0fd38533649e92756022aa46a093d94c78652a4615aae23c846db60"

have_hash() {
	if command -v sha256sum >/dev/null 2>&1; then
		echo "$SHA256  $1" | sha256sum -c --status
	else
		php -r 'exit(hash_file("sha256", $argv[1]) === $argv[2] ? 0 : 1);' "$1" "$SHA256"
	fi
}

if [[ -f "$PHAR" ]] && have_hash "$PHAR"; then
	exit 0
fi

if [[ -f "$PHAR" ]]; then
	echo "tests/phpunit.phar checksum mismatch; re-downloading PHPUnit ${VERSION}" >&2
	rm -f "$PHAR"
fi

TMP="${PHAR}.download.$$"
cleanup() { rm -f "$TMP"; }
trap cleanup EXIT

if command -v curl >/dev/null 2>&1; then
	curl -fsSL --retry 3 -o "$TMP" "$URL"
elif command -v wget >/dev/null 2>&1; then
	wget -q -O "$TMP" "$URL"
else
	echo "Need curl or wget to download PHPUnit ${VERSION}" >&2
	exit 1
fi

if ! have_hash "$TMP"; then
	echo "Downloaded PHPUnit phar failed SHA-256 check" >&2
	exit 1
fi

mv "$TMP" "$PHAR"
chmod +x "$PHAR"
trap - EXIT
echo "PHPUnit ${VERSION} ready at tests/phpunit.phar"
