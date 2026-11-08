# slimfony demo

A complete service built on [slimfony/core](https://github.com/slimfony/core): PHP 8.4 on FrankenPHP in worker mode, with live numbers read from the running system.

```bash
docker compose up -d --build --wait
```

| What | Where |
| --- | --- |
| The page | <http://localhost:8080> (HTTPS with a local certificate: <https://localhost:8443>) |
| Grafana (anonymous, read only) | <http://localhost:3000> |

Docker needs about 2 GB of memory for this stack.

## What runs

| Service | Role |
| --- | --- |
| `web` | FrankenPHP with 4 PHP workers, serves the API and the static page |
| `postgres` + `pgbouncer` | database, reached through a transaction pooler |
| `nats` | JetStream message broker |
| `prometheus` + `grafana` | metrics, scraped every 2 seconds, with a provisioned dashboard |
| `init` | generates random secrets into a volume once; nothing secret is in the repository |

The containers run as a non-root user, with a read-only file system, no capabilities and no new privileges. PHP and Caddy use production settings: no timestamp validation, JIT, no error output, request and header limits.

## API

| Route | Purpose |
| --- | --- |
| `GET /healthz`, `/readyz` | liveness and readiness (database, NATS, Prometheus) |
| `GET /metrics` | Prometheus metrics, bearer token required |
| `GET /api/runtime` | PHP, JIT, server and core versions, uptime, memory |
| `GET /api/status` | state of every dependency |
| `GET /api/stats` | requests per second, p95 and total, read from Prometheus |

## Development

```bash
composer install
composer ci          # tests, PHPStan (max), code style
bash scripts/e2e.sh  # starts the stack and checks it
```

## Status

The foundation is done: the stack, the API above and the page. The statement import (ZIP files with bank statements, per-statement transactions, concurrent importers), authentication with a small identity provider, the live tile grid with a log view, and the k6 benchmark are next.

## License

MIT
