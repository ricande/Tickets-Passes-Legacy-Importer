#!/usr/bin/env bash
# Syntax-check first-party PHP (plugin + tests). Excludes phpunit.phar.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
fail=0
while IFS= read -r -d '' file; do
	if ! php -l "$file"; then
		fail=1
	fi
done < <(find . \
	\( -path './.git' -o -path './dist' -o -path './vendor' -o -path './node_modules' -o -path './tests/.uploads' -o -path './tests/.phpunit.cache' \) -prune \
	-o -name '*.php' -type f -print0)
if [[ "$fail" -ne 0 ]]; then
	exit 1
fi
echo "php_lint_ok"
