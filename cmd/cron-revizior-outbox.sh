#!/usr/bin/env bash
# ReviziOR outbox — doručení událostí o dokladech (spouštět každou minutu).
#   * * * * * /cesta/k/projektu/cmd/cron-revizior-outbox.sh >> /var/log/myinvoice-outbox.log 2>&1
set -euo pipefail
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec php "$PROJECT_ROOT/api/bin/cron-revizior-outbox.php" "$@"
