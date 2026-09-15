#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
bash tests/ensure-phpunit.sh
php tests/phpunit.phar -c phpunit.xml
