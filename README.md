# slimfony demo

A complete service built on [slimfony/core](https://github.com/slimfony/core): PHP 8.4 on FrankenPHP in worker mode, with live numbers read from the running system.

```bash
docker compose up -d --build --wait
```

| What | Where |
| --- | --- |
| The page | <http://localhost:8080> (HTTPS with a local certificate: <https://localhost:8443>) |
| Grafana (anonymous, read only) | <http://localhost:3000> |

Docker needs about 3 GB of memory for this stack.

## What runs

| Service | Role |
| --- | --- |
| `web` | FrankenPHP with 4 PHP workers, serves the API and the static page |
| `postgres` + `pgbouncer` | database, reached through a transaction pooler |
| `idp` | small identity provider: publishes its keys (JWKS) over HTTPS and hands out demo tokens |
| `relay` | command line process that publishes the outbox rows to NATS JetStream (several can run side by side) |
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
| `POST /api/demo-token?kind=…` | a token from the demo identity provider: `valid`, `expired`, `bad_signature`, `wrong_audience` or `missing_scope` |
| `POST /api/imports?mode=partial\|strict` | upload a ZIP of statements; needs a valid token with scope `import:write` |
| `POST /public/drop` | the same upload without a token: anonymous sandbox with tighter limits |
| `GET /api/batches/{id}` | state of an upload; needs scope `import:read` |

Every route needs a verified token unless it is marked public (deny by default); a request for an unknown path gets 401 as well, so a stranger cannot map the API. An accepted upload is stored together with an outbox row in one transaction; the batch records whether it came with a token (`jwt`), who sent it and which key vouched for it, or came from the sandbox (`public`). The demo identity provider gives tokens to anybody: that is the demo, a real provider authenticates first.

## Development

```bash
composer install
composer ci          # tests, PHPStan (max), code style
bash scripts/e2e.sh  # starts the stack and checks it
```

## Status

Done: the stack, the API above, authentication with the identity provider, both upload paths and the page. The outbox relay publishes every accepted upload to a NATS JetStream stream, at least once, with the row id as message id so repeats are dropped. Next: the importer (statement parsing, per-statement transactions, concurrent importers), the live tile grid with a log view, and the k6 benchmark.

## License

MIT
