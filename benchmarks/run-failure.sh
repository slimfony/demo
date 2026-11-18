#!/usr/bin/env bash
# Failure injection under load. Needs the stack up. A steady authenticated read runs for 150 s (k6 in a container, on the
# stack's network) while the script breaks things at fixed times; the result shows, per 5-second slice, the failed
# requests and the p99, and what the retry counters did.
#
#   t=20 s  pgbouncer restarted        (every pooled connection is gone)
#   t=50 s  postgres restarted         (the database is away for a few seconds, then comes back)
#   t=85 s  idp stopped, t=115 s idp started (the keys the service has are all it has for 30 s)
#   t=140 s a long database statement is not needed: nothing else is injected, the tail shows the steady state again
#
#   bash benchmarks/run-failure.sh [results-dir]
set -euo pipefail
cd "$(dirname "$0")/.."
out=${1:-benchmarks/results/$(date +%Y-%m-%d)-failure}
mkdir -p "$out"
network=$(docker compose ps -q web | xargs docker inspect --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')
work=$(pwd -W 2>/dev/null || pwd)
log() { echo "$(( $(date +%s) - started ))s $*" | tee -a "$out/events.txt"; }
: > "$out/events.txt"

started=$(date +%s)
( MSYS_NO_PATHCONV=1 docker run --rm --network "$network" -v "$work/benchmarks:/work" -w /work \
    -e TARGET=http://web:8080 -e RATE="${RATE:-1500}" -e SECONDS=150 \
    grafana/k6:latest run --quiet --summary-export="/work/${out#benchmarks/}/failure.json" k6/failure.js >"$out/k6.txt" 2>&1 ) &
k6=$!

at() { while [ $(( $(date +%s) - started )) -lt "$1" ]; do sleep 0.5; done; }
at 20; log "restart pgbouncer";  docker compose restart pgbouncer >/dev/null 2>&1; log "pgbouncer restart returned"
at 50; log "restart postgres";   docker compose restart postgres >/dev/null 2>&1;  log "postgres restart returned"
at 85; log "stop idp";           docker compose stop idp >/dev/null 2>&1
at 115; log "start idp";         docker compose start idp >/dev/null 2>&1
wait "$k6" || true
# the retry counters of the whole run, from Prometheus
docker compose exec -T prometheus wget -qO- 'http://127.0.0.1:9090/api/v1/query?query=sum%20by%20(job%2Creason)%20(increase(db_retries_total%5B4m%5D))' >"$out/retries.json" 2>/dev/null || true
docker compose exec -T prometheus wget -qO- 'http://127.0.0.1:9090/api/v1/query?query=sum%20by%20(job%2Creason)%20(increase(db_retries_exhausted_total%5B4m%5D))' >"$out/retries-exhausted.json" 2>/dev/null || true
echo "failure run done: $out"
