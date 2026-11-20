#!/usr/bin/env bash
#
# Runs lint plus the plugin's pure-logic unit tests in a throwaway PHP
# container; no local PHP/WordPress needed, just Docker. If the Docker daemon
# is not reachable, falls back to a local `php` (set FE_USE_LOCAL_PHP=1 to
# force that). Domain classes carry no WordPress dependency (FE_TESTING in
# tests/bootstrap.php). Hooks, DB and admin behavior are checked on a real
# WooCommerce site (docs/testing-checklist.md).
#
# Usage:  bin/test.sh          # lint + unit tests
#         bin/test.sh lint     # syntax lint only
set -euo pipefail

cd "$(dirname "$0")/.."
IMAGE="php:8.2-cli"
PHAR_URL="https://phar.phpunit.de/phpunit-9.phar"

use_docker() {
  [ "${FE_USE_LOCAL_PHP:-}" != "1" ] && command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1
}

lint() {
  if use_docker; then
    docker run --rm -v "$PWD":/app -w /app "$IMAGE" sh -c '
      fail=0
      for f in $(find . -name "*.php" -not -path "./vendor/*"); do
        php -l "$f" >/dev/null 2>&1 || { php -l "$f"; fail=1; }
      done
      [ $fail -eq 0 ] && echo "lint: no syntax errors" || { echo "lint: FAILED"; exit 1; }
    '
  else
    fail=0
    while IFS= read -r f; do
      php -l "$f" >/dev/null 2>&1 || { php -l "$f"; fail=1; }
    done < <(find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*")
    if [ $fail -eq 0 ]; then echo "lint: no syntax errors"; else echo "lint: FAILED"; exit 1; fi
  fi
}

unit() {
  if use_docker; then
    docker run --rm -v "$PWD":/app -w /app "$IMAGE" sh -c '
      [ -f /tmp/phpunit.phar ] || php -r "copy(\"'"$PHAR_URL"'\", \"/tmp/phpunit.phar\");"
      php /tmp/phpunit.phar --bootstrap tests/bootstrap.php --testdox tests
    '
  else
    phar="${TMPDIR:-/tmp}/phpunit-9.phar"
    [ -f "$phar" ] || php -r "copy('$PHAR_URL', '$phar');"
    php "$phar" --bootstrap tests/bootstrap.php --testdox tests
  fi
}

lint
if [ "${1:-}" != "lint" ]; then
  unit
fi
