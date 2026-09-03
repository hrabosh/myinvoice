#!/usr/bin/env bash
# Starts ReviziOR Fakturace from this checkout and joins the running ReviziOR
# backend network under the stable hostname `revizior-invoice.local`.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

REVIZIOR_NETWORK="${REVIZIOR_DOCKER_NETWORK:-backend_revizor}"
export REVIZIOR_DOCKER_NETWORK="$REVIZIOR_NETWORK"
export APP_PORT="${APP_PORT:-8082}"
export MYINVOICE_INSTALL_MODE=source

if ! docker network inspect "$REVIZIOR_NETWORK" >/dev/null 2>&1; then
  echo "ERROR: Docker network '$REVIZIOR_NETWORK' does not exist." >&2
  echo "Start the ReviziOR backend stack first: docker compose -f ../backend/docker-compose.yml up -d" >&2
  exit 1
fi

# Generates gitignored .env/cfg.docker.php, builds the current checkout, runs
# migrations and waits for the public health endpoint.
"$PROJECT_ROOT/cmd/docker-install.sh" --build

DC=(docker compose -f docker-compose.yml -f docker-compose.revizior-local.yml)
"${DC[@]}" up -d db app

echo "==> Verifying the shared Docker network…"
BACKEND_CONTAINER="$(docker ps \
  --filter "network=$REVIZIOR_NETWORK" \
  --filter 'label=com.docker.compose.service=php' \
  --format '{{.Names}}' | head -1)"

if [[ -n "$BACKEND_CONTAINER" ]]; then
  docker exec "$BACKEND_CONTAINER" php -r '
    $url = "http://revizior-invoice.local/api/health";
    $body = @file_get_contents($url);
    if ($body === false) { fwrite(STDERR, "Backend cannot reach $url\n"); exit(1); }
    $health = json_decode($body, true);
    if (($health["status"] ?? null) !== "ok" || !($health["db"] ?? false)) {
        fwrite(STDERR, "ReviziOR Fakturace health check is not ready\n");
        exit(1);
    }
    echo "Backend -> ReviziOR Fakturace: OK\n";
  '
else
  echo "WARNING: no running backend php service found on '$REVIZIOR_NETWORK'; network alias is configured but not probed." >&2
fi

echo ""
echo "ReviziOR Fakturace: http://localhost:${APP_PORT}"
echo "Backend container URL: http://revizior-invoice.local"
echo "Compose: docker compose -f docker-compose.yml -f docker-compose.revizior-local.yml"
