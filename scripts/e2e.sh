#!/usr/bin/env bash
# Starts the stack and checks the real thing: every service healthy, the api answering, metrics protected,
# and both upload paths with good and bad tokens (the identity provider, TLS and the database included).
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose up -d --build --wait --wait-timeout 300
trap 'docker compose logs --tail 50; docker compose down -v' ERR

base=http://localhost:8080
fail() { echo "FAIL: $*" >&2; exit 1; }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

[ "$(status $base/healthz)" = 200 ] || fail "/healthz"
[ "$(status $base/readyz)" = 200 ] || fail "/readyz"
[ "$(status $base/metrics)" = 401 ] || fail "/metrics must refuse anonymous requests"
curl -sf $base/api/runtime | grep -q '"worker_mode":true' || fail "/api/runtime is not in worker mode"
curl -sf $base/ | grep -q 'PHP never left' || fail "the page is not served"

# ZIPs of generated CAMT.053 statements: ten good ones, and three that are all wrong (the same seed always gives the same files)
zip=$(mktemp)
bad=$(mktemp)
curl -sf "$base/api/sample.zip?seed=1&files=10" -o "$zip"
curl -sf "$base/api/sample.zip?seed=2&files=3&fault_rate=1&fault=balance_mismatch" -o "$bad"

token() { curl -sf -X POST "$base/api/demo-token?kind=$1" | sed 's/.*"token":"\([^"]*\)".*/\1/'; }
upload() { status -X POST -H "Authorization: Bearer $(token "$1")" --data-binary @"$zip" "$base/api/imports"; }

[ "$(status -X POST --data-binary @"$zip" $base/api/imports)" = 401 ] || fail "an upload without a token must be 401"
[ "$(upload expired)" = 401 ] || fail "an expired token must be 401"
[ "$(upload bad_signature)" = 401 ] || fail "a forged token must be 401"
[ "$(upload wrong_audience)" = 401 ] || fail "a token for another audience must be 401"
[ "$(upload missing_scope)" = 403 ] || fail "a token without the scope must be 403"

response=$(curl -sf -X POST -H "Authorization: Bearer $(token valid)" --data-binary @"$zip" "$base/api/imports")
echo "$response" | grep -q '"source":"jwt"' || fail "the valid upload was not accepted: $response"
id=$(echo "$response" | sed 's/.*"id":"\([^"]*\)".*/\1/')
curl -sf -H "Authorization: Bearer $(token valid)" "$base/api/batches/$id" | grep -q '"subject":"demo-client"' || fail "the batch does not record who sent it"

# the importers pick the batch up from NATS and book it: wait for the result
wait_for() { for _ in $(seq 1 40); do curl -sf -H "Authorization: Bearer $(token valid)" "$base/api/batches/$1" | grep -q "$2" && return 0; sleep 1; done; return 1; }
wait_for "$id" '"status":"done".*"statements_ok":10' || fail "the batch of ten good statements was not imported"

# strict mode: one bad statement rolls the whole ZIP back
strict=$(curl -sf -X POST -H "Authorization: Bearer $(token valid)" --data-binary @"$bad" "$base/api/imports?mode=strict" | sed 's/.*"id":"\([^"]*\)".*/\1/')
wait_for "$strict" '"status":"failed".*"statements_ok":0' || fail "the strict batch with bad statements was not rolled back"

[ "$(status -X POST --data-binary @"$zip" $base/public/drop)" = 202 ] || fail "the public sandbox must accept a small ZIP"
[ "$(status -X POST --data 'not a zip' $base/public/drop)" = 422 ] || fail "the public sandbox must refuse a file that is not a ZIP"

# the relay publishes the outbox to NATS: wait until nothing is left unpublished and the stream holds the messages
for _ in $(seq 1 30); do
  pending=$(docker compose exec -T postgres psql -U demo -d demo -tAc 'SELECT count(*) FROM outbox WHERE published_at IS NULL')
  [ "$pending" = 0 ] && break
  sleep 1
done
[ "$pending" = 0 ] || fail "the relay did not publish the outbox"
docker compose exec -T nats wget -qO- 'http://127.0.0.1:8222/jsz?streams=true' | grep -Eq '"messages": *[1-9]' || fail "the NATS stream holds no messages"

# the relay's own metrics reach Prometheus (scraped from the relay's port with the metrics token)
for _ in $(seq 1 20); do
  sent=$(docker compose exec -T prometheus wget -qO- 'http://127.0.0.1:9090/api/v1/query?query=sum(outbox_published_total)' | sed -n 's/.*"value":\[[0-9.]*,"\([0-9.]*\)"\].*/\1/p')
  [ -n "$sent" ] && [ "${sent%.*}" -ge 1 ] && break
  sleep 1
done
[ -n "$sent" ] && [ "${sent%.*}" -ge 1 ] || fail "Prometheus does not see the relay's metrics"

echo "e2e ok"
docker compose down -v
