#!/usr/bin/env bash
# Runs the whole benchmark: both API routes, then the import lane for 1, 2, 4 and 8 importers, with and without PgBouncer.
# Takes 15 to 25 minutes. Results (raw k6 summaries, import figures, the machine's description) go to benchmarks/results/<date>/,
# and the Markdown tables to benchmarks/results/<date>/README.md.
#
# Run it on a quiet machine: the load generator and the system under test share the same CPUs.
set -euo pipefail
cd "$(dirname "$0")/.."

out=benchmarks/results/${1:-$(date +%Y-%m-%d)}
mkdir -p "$out"

{
  echo "Date: $(date -u +%Y-%m-%dT%H:%MZ)"
  echo "Host: $(powershell -NoProfile -Command '(Get-CimInstance Win32_Processor | Select-Object -First 1).Name; "RAM: " + [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory/1GB) + " GB"' 2>/dev/null | tr '\r\n' '  ' || uname -a)"
  echo "Docker: $(docker info --format 'engine {{.ServerVersion}}, {{.NCPU}} CPUs, {{.MemTotal}} bytes memory')"
  echo "k6: $(docker run --rm grafana/k6:latest version | head -1)"
  echo "Stack: $(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD) $(docker compose images --format json 2>/dev/null | head -c 0)"
  echo "Services: web with 4 PHP workers (FrankenPHP 1.13, PHP 8.4, JIT tracing), PostgreSQL 17, PgBouncer 1.26, NATS 2.15"
} > "$out/hardware.txt"

# the API lane against the default configuration (4 importers, PgBouncer on)
unset BENCH_DB_HOST BENCH_DB_PORT BENCH_DB_POOLING
docker compose up -d --force-recreate --no-deps --scale importer=4 web relay importer >/dev/null 2>&1
sleep 15
RATES=${RATES:-1000,2500,5000,7500,10000,12500,15000} bash benchmarks/run-api.sh health "$out"
sleep 20 # let the web server settle after the highest rate
RATES=${RATES:-1000,2500,5000,7500,10000,12500,15000} bash benchmarks/run-api.sh auth "$out"

: > "$out/import.jsonl"
# every configuration is run REPS times (the machine is not quiet; the median and the range say how much to trust a number)
for rep in $(seq 1 "${REPS:-3}"); do
  for pool in on off; do
    for importers in 1 2 4 8; do
      bash benchmarks/run-import.sh "$importers" "$pool" "${ZIPS:-1000}" "$out"
    done
  done
done

# back to the default configuration
unset BENCH_DB_HOST BENCH_DB_PORT BENCH_DB_POOLING
docker compose up -d --force-recreate --no-deps --scale importer=4 web relay importer >/dev/null 2>&1

php benchmarks/report.php "$out" > "$out/README.md"
echo "done: $out/README.md"
