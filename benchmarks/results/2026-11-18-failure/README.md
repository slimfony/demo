# Failure injection under load (2026-11-18)

`bash benchmarks/run-failure.sh`: an authenticated read (verified token, database read) at 1,500 requests per second for 150 s, while the script restarts PgBouncer (20 s), restarts PostgreSQL (50 s) and stops the identity provider for 30 s (85 s to 115 s). The figures per 5-second slice are in `failure.json`, the retry counters of the run in `retries*.json`.

| | PgBouncer defaults (`-before`) | `query_wait_timeout = 3`, `server_login_retry = 1` |
|---|---|---|
| requests | 210,041 | 223,769 |
| failed (`500`) | 7,370 (3.5%) | 188 (0.1%) |
| slowest request | 15,020 ms | 4,057 ms |
| slices (5 s) with a request over 1 s | 5 | 2 |

- With the defaults a request waited up to 15 s while PostgreSQL was away (PgBouncer answered with the cached login error for `server_login_retry`), and the waiting requests held the workers. The core's `Retry` gave up after its three attempts (a few tens of milliseconds): a database that is away for seconds fails the request, which is what bounded retries mean. How long it takes to fail is the pooler's setting.
- The identity provider being down for 30 s changed nothing: no failed request, p99 unchanged (the keys the service had stayed in use).
- The importers, the relay and the generator exited when the database went away and Docker started them again; the work they had in flight was redone from the outbox and the queue.
- Known limit: the run is one machine, one restart of each component, and the numbers of the second column are from one run (the first run of the defaults was repeated once after a faulty first attempt had two controllers running; the table is the clean one).
