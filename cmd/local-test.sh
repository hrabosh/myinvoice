#!/usr/bin/env bash
# Spustí příkaz v kontejneru s PHP 8.5 nad tímto checkoutem, připojený k DB stacku.
# Použití: cmd/local-test.sh php api/bin/migrate.php | cmd/local-test.sh vendor/bin/phpunit --testsuite Unit
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
exec docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp --network fakturacereviziorcz_default \
  -v "$PWD:/repo" -w /repo/api --entrypoint "" myinvoice:latest "$@"
