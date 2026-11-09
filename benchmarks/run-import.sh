#!/usr/bin/env bash
# The import lane for one configuration: the number of importers and whether PgBouncer sits between the services and PostgreSQL.
# Starts the services in that configuration, empties the data, uploads ZIPs with k6, waits until every batch is finished
# and appends one line of figures to results-dir/import.jsonl.
#
#   bash benchmarks/run-import.sh <importers> <on|off> [zips] [results-dir]
set -euo pipefail
cd "$(dirname "$0")/.."

importers=${1:?importers}
pool=${2:?on|off}
zips=${3:-1000}
out=${4:-benchmarks/results/$(date +%Y-%m-%d)}
mkdir -p "$out"
sql() { docker compose exec -T postgres psql -U demo -d demo -tAc "$1"; }

if [ "$pool" = off ]; then
  export BENCH_DB_HOST=postgres BENCH_DB_PORT=5432 BENCH_DB_POOLING=false
else
  export BENCH_DB_HOST=pgbouncer BENCH_DB_PORT=6432 BENCH_DB_POOLING=true
fi

echo "== $importers importer(s), pooler $pool, $zips ZIPs"
docker compose up -d --force-recreate --no-deps --scale importer="$importers" --wait web relay importer >/dev/null 2>&1 || docker compose up -d --force-recreate --no-deps --scale importer="$importers" web relay importer >/dev/null
for _ in $(seq 1 60); do curl -sf http://localhost:8080/readyz >/dev/null 2>&1 && break; sleep 1; done
sleep 8 # the importers connect to NATS and start pulling

# not RESTART IDENTITY: the broker drops a message whose id (the outbox row id) it saw in the last two minutes
sql "TRUNCATE batches, outbox CASCADE" >/dev/null
network=$(docker compose ps -q web | xargs docker inspect --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')
work=$(pwd -W 2>/dev/null || pwd)
base=$(( ($(date +%s) % 800000) * 1000 + 1 ))

MSYS_NO_PATHCONV=1 docker run --rm --network "$network" -v "$work/benchmarks:/work" -w /work \
  -e TARGET=http://web:8080 -e ZIPS="$zips" -e VUS="${VUS:-24}" -e SEED_BASE="$base" \
  grafana/k6:latest run --quiet --summary-export="/work/results/$(basename "$out")/import-$importers-$pool-k6.json" k6/import.js >/dev/null

for _ in $(seq 1 600); do
  open=$(sql "SELECT count(*) FROM batches WHERE status IN ('queued', 'running')")
  [ "$open" = 0 ] && break
  sleep 1
done
sleep 3 # Prometheus scrapes every two seconds: let it see the final counters

retries=$(docker compose exec -T prometheus wget -qO- 'http://127.0.0.1:9090/api/v1/query?query=sum(db_retries_total{job="importer"})' | sed -n 's/.*"value":\[[0-9.]*,"\([0-9.]*\)"\].*/\1/p')
row=$(sql "SELECT count(*), count(*) FILTER (WHERE status = 'done'), count(*) FILTER (WHERE status = 'failed'), coalesce(sum(statements_ok), 0), coalesce(sum(statements_failed), 0), coalesce(sum(entries_booked), 0), round(extract(epoch from (max(finished_at) - min(created_at)))::numeric, 2) FROM batches")
IFS='|' read -r total done_count failed_count booked rejected entries seconds <<<"$row"

printf '{"importers":%s,"pooler":"%s","zips":%s,"batches":%s,"done":%s,"failed":%s,"statements_booked":%s,"statements_rejected":%s,"entries":%s,"seconds":%s,"deadlock_retries":%s,"still_open":%s}\n' \
  "$importers" "$pool" "$zips" "$total" "$done_count" "$failed_count" "$booked" "$rejected" "$entries" "${seconds:-0}" "${retries:-0}" "${open:-0}" | tee -a "$out/import.jsonl"
