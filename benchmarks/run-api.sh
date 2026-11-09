#!/usr/bin/env bash
# The API lane. Needs the stack up. k6 runs in a container on the stack's own network (so the numbers are not
# distorted by the Docker Desktop port forwarding) and the CPU use of the services is sampled while it runs.
#
#   bash benchmarks/run-api.sh health  [results-dir]
#   bash benchmarks/run-api.sh auth    [results-dir]
set -euo pipefail
cd "$(dirname "$0")/.."

route=${1:-health}
out=${2:-benchmarks/results/$(date +%Y-%m-%d)}
mkdir -p "$out"
network=$(docker compose ps -q web | xargs docker inspect --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')
work=$(pwd -W 2>/dev/null || pwd)

# CPU of the web container, every two seconds (the highest value is what matters)
( while true; do docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' 2>/dev/null | grep -E 'web-1|postgres-1|pgbouncer-1' >> "$out/api-$route-stats.txt"; sleep 2; done ) &
sampler=$!
trap 'kill $sampler 2>/dev/null || true' EXIT
: > "$out/api-$route-stats.txt"
echo "${STEP_SECONDS:-15}" > "$out/api-$route.step"

MSYS_NO_PATHCONV=1 docker run --rm --network "$network" -v "$work/benchmarks:/work" -w /work \
  -e TARGET=http://web:8080 -e ROUTE="$route" -e RATES="${RATES:-1000,2500,5000,7500,10000,12500}" -e STEP_SECONDS="${STEP_SECONDS:-15}" \
  grafana/k6:latest run --quiet --summary-export="/work/results/$(basename "$out")/api-$route.json" k6/api.js

echo "api lane ($route) done: $out/api-$route.json"
