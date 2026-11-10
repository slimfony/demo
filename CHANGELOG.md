# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.6.0] - 2026-11-10

### Changed
- Uses slimfony/core 0.6.0: the garbage-collection settings of the worker are one `GarbageCollection` object (`public/index.php`).
- The page's *Runtime* table shows the released version without anyone passing it: the build reads the latest release from the changelog (`0.5.1`), and adds `+dev` while the changelog lists changes that are not released yet. `APP_VERSION` still overrides it.

## [0.5.1] - 2026-11-10

### Changed
- Uses slimfony/core 0.5.0: a database failover is retried, a body without a declared length is limited, `Bearer` and JWT values are masked in log messages, and every request carries a span id and an outgoing `traceparent`.
- Kubernetes: the web pods wait 8 seconds (`preStop`) before they stop, so the load balancer stops sending first and a deploy drops no request; the grace period is 45 seconds.

## [0.5.0] - 2026-11-10

### Changed
- The relay, the importer and the generator share their set-up (database connection, log, metrics, metrics port) in one class, `Infrastructure\ProcessSetup`, instead of three copies.
- Kubernetes: the `generator` Deployment (and `API_URL` in the config), so the page's import and "Try it" work in a cluster too.
- The page's *Runtime* table shows the version of the demo (`APP_VERSION`, a build argument; `dev` without one), and the memory as two rows with exact names: the PHP heap of a worker and the server process (what the operating system counts, the same figure as the Live section).
- **Two more database round trips are saved per statement** (eight became six): the statement and its first entries are one `WITH` statement, the balance and the statistics another. Eight importers: 1000 ZIPs in 11.5 s instead of 13.0 s. The lock order, which prevents the deadlocks, is unchanged.
- The garbage collector chart says why it is empty: the collector has not run, this code leaves no cycles to collect (measured: `benchmarks/results/2026-11-10-gc-probe/`).

### Added
- **A run of the page's import lives on the server.** The `generator` service generates and uploads the ZIPs, and also sends the requests of "Try it" (`POST /api/load/runs`, `GET /api/load/runs/latest`; 5,000, 50,000 or 200,000 requests to `/healthz` or `/api/runtime`) (import: `POST /api/import/runs` asks for a run, `GET /api/import/runs/latest` follows it), so a reload of the page no longer stops or loses a run: the page joins the run again. One run at a time (409 while one is going). New tables `import_runs`, `import_run_items` and `load_runs` in `docker/postgres/20-schema.sql`.
- The charts of the page show whole-number ticks (an empty chart is 0 to 4 and grows with the data), and the two scales of the memory chart line up with the grid.
- The page's Live section has six charts: the garbage collector (cycles freed per second) and the memory of the web server with the busy PHP workers. New numbers: memory, busy workers, garbage collector runs.
- `DEFER_GC=true` (compose: `BENCH_DEFER_GC`) runs the PHP cycle collector between requests; the collector's runs are counted (`php_gc_runs_total`, `php_gc_collected_total`).
- The page shows the API's p99 instead of p95: the slowest one in a hundred is where a garbage collector pause shows.
- Prometheus reads the PHP thread numbers of FrankenPHP (`frankenphp_busy_workers`) from an internal port (9101).

### Upgrading
- **A database volume from an earlier version does not have the three new tables** (PostgreSQL runs its init scripts only on an empty volume). Start from a clean volume: `docker compose down -v`, then `docker compose up -d --build --wait`. The demo's data is disposable.

## [0.4.0] - 2026-11-09

### Added
- `import_stage_seconds{stage}`: where the time of an imported batch goes (claim, unzip, parse, write, finish, events).

### Changed
- **Importers pull 5 messages per request** (`IMPORT_PULL_BATCH`). One message per pull cost about 41 ms of waiting per ZIP; with one importer 400 ZIPs now take 15.8 s instead of 31.7 s. With eight importers the database write is the limit and the gain is small (13.9 s to 13.0 s for 1000 ZIPs).
- One database round trip less per statement: the lock query returns the account's currency.
- The page's legend separates the tile colours (what happened to the ZIP) from the shield (how it was uploaded). New picture and recording.
- The `TokenSource` interface is gone: `HttpTokenSource` is used directly.

## [0.3.1] - 2026-11-09

### Changed
- Uses slimfony/core 0.4.0: its `ProcessMetricsEndpoint` replaces the demo's own copy.

### Fixed
- `/api/runtime` reported `worker_mode: true` in classic mode too (the function `frankenphp_handle_request()` exists there). The worker script now says it runs the worker loop; the classic entry script of the benchmarks does not.

## [0.3.0] - 2026-11-09

### Changed
- **The importers lock the shared account rows in a fixed order** (sorted by IBAN) and update the statistics in one statement. No more deadlocks between importers, and 1,000 ZIPs take 14 s with eight importers instead of 67 s. The old behaviour is a choice (`lock_order=appearance`, a selector on the page).
- **16 PHP workers by default** (`PHP_WORKERS`, `PHP_THREADS`): workers that wait for the database are idle, so there should be more of them than cores. The authenticated read now saturates at about 12,400 requests per second instead of 6,500.

### Added
- **A browser test in CI** (Playwright, `e2e/`): the page in a real browser, an import from the first upload to the result card, tokens, the theme. A test tool only: the page keeps its no-build, no-dependency form.
- **The README picture and recording** come from a real run (`HERO=1 npx playwright test hero`).
- **Benchmarks** (`benchmarks/`): k6 scripts for the API and the import lane, runners, a report generator and the first results, with the machine described.
- **Kubernetes manifests** (`k8s/`): probes, security context, autoscaling, disruption budgets, network policies.
- `scripts/rename.php`: gives the code your own namespace and package name, to start a service from this repository.
- `DB_TRANSACTION_POOLING` (and the matching compose variables) to run without PgBouncer, for the benchmark.

## [0.2.0] - 2026-11-09

### Added
- **No batch is forgotten:** a sweeper in the relay announces again every batch that has waited (or been `running`) for too long; an importer gives up a batch after the last delivery the broker allows, and the batch ends as `failed` with the reason `gave_up` on record.
- **Chaos switches** (`CHAOS_ENABLED`, scope `chaos:write`): kill an importer in the middle of a batch, cut the database connections; "account contention: high" on the page. `scripts/chaos.sh` runs the scenario and checks that no ZIP is lost; it is part of `scripts/e2e.sh`.
- The page follows an import through the database (once a second); events only make the tiles move at once.
- Importer timeouts from the environment (`IMPORT_ACK_WAIT_SECONDS`), the relay's sweep delay (`SWEEP_AFTER_SECONDS`).

### Fixed
- A batch that failed after every retry stayed `running` for ever; it is given back, and an abandoned one is taken again.
- Strict mode locks the accounts of a whole ZIP in a fixed order first, so ZIPs wait for each other instead of deadlocking.
- Tiles stuck as "waiting" when the event stream lost events.

## [0.1.0] - 2026-11-08

First release: a complete service on slimfony/core 0.3.0, started with one `docker compose up`.

### Added
- **The stack:** FrankenPHP in worker mode (non-root, read-only file system, production PHP and Caddy settings), PostgreSQL behind PgBouncer, NATS JetStream, Prometheus and Grafana with a provisioned dashboard, random secrets generated on first start.
- **Identity provider** (second service from the same image): EdDSA tokens, key list (JWKS) over HTTPS from a private CA, five kinds of demo token (valid, expired, forged, wrong audience, missing permission) and key rotation.
- **Two upload routes:** `POST /api/imports` with a verified token (deny by default, scopes, sender and key id recorded) and `POST /public/drop`, an anonymous sandbox with tighter limits and a rate limit. ZIPs are inspected (size, file names, compression ratio) before anything is stored.
- **Outbox and relay:** an accepted upload and its outbox row are stored in one transaction; relays publish the rows to NATS at least once (`FOR UPDATE SKIP LOCKED`, message id for de-duplication).
- **Importers** (any number, side by side): a reduced CAMT.053 schema, bounded and DTD-free XML reading with schema validation, business rules (balance, account, currency, period, duplicates), one transaction per statement (`partial`) or one transaction per ZIP with a savepoint per statement (`strict`, with locks taken in a fixed order). Deadlocks are run again by the core's `Retry`; a batch that fails is given back, an abandoned one is taken again, and a repeated run books nothing twice.
- **Generator** of valid CAMT.053 statements and of statements that are wrong in one specific way; the same seed gives the same files.
- **The page:** import of up to 1,000 ZIPs with a tile per ZIP, live numbers, result card and log (through a Mercure hub in the web server); tokens and keys; a request test; live API and importer metrics; the latest batches; light and dark theme; a presenter mode and a short guided tour.
- **Metrics** of the API, the relay and every importer, scraped by Prometheus; Grafana panels for all of them.
- **Quality:** PHPStan at max level, PER-CS, behavioural tests (also against a real PostgreSQL), an end-to-end script that starts the stack and checks it, CI on GitHub Actions.

[Unreleased]: https://github.com/slimfony/demo/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/slimfony/demo/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/slimfony/demo/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/slimfony/demo/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/slimfony/demo/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/slimfony/demo/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/slimfony/demo/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/slimfony/demo/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/slimfony/demo/releases/tag/v0.1.0
