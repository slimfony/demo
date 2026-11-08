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

# a small ZIP with two files, made inside the container (it has PHP and ext-zip)
zip=$(mktemp)
docker compose exec -T web php -r '$z = new ZipArchive(); $p = tempnam("/tmp", "z"); $z->open($p, ZipArchive::OVERWRITE); $z->addFromString("a.xml", "<Document/>"); $z->addFromString("b.xml", "<Document/>"); $z->close(); echo file_get_contents($p);' > "$zip"

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

[ "$(status -X POST --data-binary @"$zip" $base/public/drop)" = 202 ] || fail "the public sandbox must accept a small ZIP"
[ "$(status -X POST --data 'not a zip' $base/public/drop)" = 422 ] || fail "the public sandbox must refuse a file that is not a ZIP"

echo "e2e ok"
docker compose down -v
