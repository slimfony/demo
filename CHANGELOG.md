# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

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
