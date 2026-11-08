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

## On the page

- **Import.** Press *Start import*: the page generates up to 1,000 ZIPs of 10 statements each and uploads them to the API (19 in 20 with a token, the rest through the anonymous sandbox). One tile per ZIP shows where it is: waiting, importing, booked, booked with rejected statements, rolled back, refused. A shield marks the uploads that came with a verified token. Numbers, a result card and a live log of the services follow.
- **Tokens and keys.** Send a ZIP with a valid or a broken token and see why it is refused. Rotate the identity provider's signing key and see the API pick up the new one.
- **Send requests.** The browser sends a few thousand requests to the API as fast as it can.
- **Live** (requests per second and latency from Prometheus), **Batches** (the latest ZIPs), **Present** (a big-screen view) and a short guided tour.

## The demo case: importing bank statements

A bank sends its customers' account statements as ZIP files. Each ZIP holds XML files in the ISO 20022 **CAMT.053** format (a reduced version of the schema is in [schema/](schema/)), one statement per file. The service has to take thousands of these in at once and book every statement correctly.

What the demo shows, with a real service and not a toy endpoint:

- **Taking uploads in.** Two ways in: with a token (`/api/imports`, the sender is known and recorded) and as an anonymous sandbox (`/public/drop`, small files only, rate limited). Every broken token is refused for its own reason: expired, forged, wrong audience, missing permission.
- **Booking each statement all or nothing.** A statement, its entries and the balance of its account are written in one transaction. The files are checked against the XSD first, then against the business rules: the opening balance plus the entries must give the closing balance, the account must be one of ours and in the same currency, the entries must lie in the statement period, nothing may be imported twice.
- **Two ways to treat a bad statement.** `partial`: the bad statement is rolled back and recorded, the other nine stay. `strict`: the whole ZIP is one transaction with a savepoint per statement; every mistake is reported, then everything is rolled back, the nine good statements too, and each is accounted for.
- **Many importers at once.** Importers share the work through NATS JetStream. They update the same few accounts, so they run into deadlocks; the database ends one transaction and the core's `Retry` runs it again. The retries are counted and visible.
- **Seeing it work.** Every service exposes metrics, Prometheus scrapes them every two seconds, and the Grafana dashboard and the page show the same numbers.

The statements come from a generator that makes valid files and, on request, files that are wrong in one specific way (a wrong balance, an unknown account, a schema error, and so on), so every rule can be seen doing its job.

## What runs

| Service | Role |
| --- | --- |
| `web` | FrankenPHP with 4 PHP workers, serves the API and the static page |
| `postgres` + `pgbouncer` | database, reached through a transaction pooler |
| `idp` | small identity provider: publishes its keys (JWKS) over HTTPS and hands out demo tokens |
| `relay` | command line process that publishes the outbox rows to NATS JetStream (several can run side by side) |
| `importer` (4 replicas) | take batches from NATS and book the statements; `docker compose up --scale importer=8` runs more |
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
| `GET /api/sample.zip?seed=&files=&fault_rate=&fault=` | a ZIP of generated CAMT.053 statements (the same seed gives the same ZIP; some can be made wrong on purpose) |
| `GET /api/batches`, `GET /api/import/summary` | the latest batches, and what the importers have done so far (public) |
| `POST /api/demo-rotate` | the identity provider starts signing with a new key |
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

Done: the stack, the API, authentication with key rotation, both upload paths, the outbox relay, the importers (reduced CAMT.053 schema, business rules, `partial` and `strict` mode, deadlock retries) and the page with the live import view. Next: chaos switches (kill a connection, force a deadlock), importer dashboards in Grafana, the k6 benchmark, Kubernetes manifests and the template repository.

## License

MIT
