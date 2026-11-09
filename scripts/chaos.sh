#!/usr/bin/env bash
# Breaks the running stack while an import is in progress and checks that nothing is lost:
# every uploaded ZIP must end up booked or failed on record, none may stay waiting or running.
# Needs the stack to be up (docker compose up) with CHAOS_ENABLED (the compose file turns it on).
set -euo pipefail
cd "$(dirname "$0")/.."

base=${BASE:-http://localhost:8080}
zips=${ZIPS:-150}
fail() { echo "FAIL: $*" >&2; exit 1; }
sql() { docker compose exec -T postgres psql -U demo -d demo -tAc "$1"; }
token() { curl -sf -X POST "$base/api/demo-token?kind=valid" | sed 's/.*"token":"\([^"]*\)".*/\1/'; }

started=$(date +%s)
before=$(sql "SELECT count(*) FROM batches")
restarts_before=$(docker compose logs importer 2>/dev/null | grep -c '"Importer started"' || true)
tok=$(token)

upload() {
  curl -sf "$base/api/sample.zip?files=10&seed=$((RANDOM * 32768 + RANDOM + $1))&fault_rate=0.05" -o "/tmp/chaos-$1.zip" &&
    curl -sf -o /dev/null -X POST -H "Authorization: Bearer $tok" --data-binary @"/tmp/chaos-$1.zip" "$base/api/imports" && rm -f "/tmp/chaos-$1.zip"
}
export -f upload
export base tok

echo "uploading $zips ZIPs, and breaking things while they go through..."
(seq 1 "$zips" | xargs -P 6 -I{} bash -c 'upload {}') &
uploads=$!

sleep 1.5
curl -sf -X POST -H "Authorization: Bearer $tok" "$base/api/chaos/kill-importer"; echo
curl -sf -X POST -H "Authorization: Bearer $tok" "$base/api/chaos/kill-importer"; echo
sleep 1
curl -sf -X POST -H "Authorization: Bearer $tok" "$base/api/chaos/cut-connections"; echo
wait "$uploads"

total=$(( $(sql "SELECT count(*) FROM batches") - before ))
[ "$total" -eq "$zips" ] || fail "$zips ZIPs were uploaded but $total batches exist"

# a batch whose message was lost: it waits, and nothing announces it (no outbox row). Only the sweeper can save it.
sql "INSERT INTO batches (id, sha256, mode, source, size_bytes, entries, payload, created_at) SELECT gen_random_uuid(), sha256, mode, source, size_bytes, entries, payload, now() - interval '5 minutes' FROM batches ORDER BY created_at LIMIT 1" >/dev/null
zips=$((zips + 1))

echo "waiting for every batch to finish (a killed importer's batch is taken again after its timeout, the lost one is announced again by the sweeper)..."
for _ in $(seq 1 240); do
  open=$(sql "SELECT count(*) FROM batches WHERE status IN ('queued', 'running')")
  [ "$open" = 0 ] && break
  sleep 1
done
[ "$open" = 0 ] || fail "$open batches are still waiting or running after four minutes"

done_count=$(sql "SELECT count(*) FROM batches WHERE status = 'done'")
failed_count=$(sql "SELECT count(*) FROM batches WHERE status = 'failed'")
restarts=$(( $(docker compose logs importer 2>/dev/null | grep -c '"Importer started"' || true) - restarts_before ))
swept=$(docker compose logs relay 2>/dev/null | grep -c 'announced again' || true)
[ "$swept" -ge 1 ] || fail "the sweeper did not announce the lost batch"
echo "all $zips ZIPs finished in $(( $(date +%s) - started )) s: $done_count done, $failed_count failed on record, $restarts importer restarts"
[ "$restarts" -ge 1 ] || fail "no importer was restarted: the kill did not happen"
echo "chaos ok"
