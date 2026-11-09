# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

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
