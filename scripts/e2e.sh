#!/usr/bin/env bash
# Starts the stack and checks the real thing: every service healthy, the api answering, metrics protected.
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose up -d --build --wait --wait-timeout 300
trap 'docker compose logs --tail 50; docker compose down -v' ERR

base=http://localhost:8080
fail() { echo "FAIL: $*" >&2; exit 1; }

[ "$(curl -s -o /dev/null -w '%{http_code}' $base/healthz)" = 200 ] || fail "/healthz"
[ "$(curl -s -o /dev/null -w '%{http_code}' $base/readyz)" = 200 ] || fail "/readyz"
[ "$(curl -s -o /dev/null -w '%{http_code}' $base/metrics)" = 401 ] || fail "/metrics must refuse anonymous requests"
curl -sf $base/api/runtime | grep -q '"worker_mode":true' || fail "/api/runtime is not in worker mode"
curl -sf $base/ | grep -q 'PHP never left' || fail "the page is not served"

echo "e2e ok"
docker compose down -v
