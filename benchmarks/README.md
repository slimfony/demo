# Benchmarks

Two lanes, measured with [k6](https://k6.io) running in a container on the stack's own network (so the Docker Desktop port forwarding does not distort the numbers).

| Lane | What it measures |
| --- | --- |
| **API** | how many requests per second the API answers and how its latency changes as the rate rises; `GET /healthz` (no authentication, no database) and `GET /api/batches/{id}` (a verified token and a database read) |
| **Import** | 1,000 ZIPs of 10 statements each, uploaded through the API, from the first upload to the last booked statement, with 1, 2, 4 and 8 importers, with and without PgBouncer |

```bash
bash benchmarks/run-all.sh            # everything, about 40 minutes (3 runs of every import configuration)
bash benchmarks/run-api.sh health     # one API route
bash benchmarks/run-import.sh 4 on    # one import configuration: 4 importers, PgBouncer on
php benchmarks/report.php benchmarks/results/<date>   # the raw results as Markdown tables
```

Run it on a quiet machine: the load generator and the system under test share the same CPUs.

## Method

- The API lane runs each rate for 15 seconds as a **constant arrival rate**: k6 starts requests at that pace whether or not the server keeps up. A server that cannot keep up shows a rising latency (requests queue up), not a polite slowdown.
- The import lane empties the tables between runs but never restarts the outbox ids: the broker drops a message whose id it has seen in the last two minutes, and a repeated id would make a message vanish.
- Every import configuration runs three times; the report gives the median and the range.
- The machine, the software versions and the date are written next to the raw results (`hardware.txt`).

## Results

Machine: i5-14400 (16 CPUs and 8 GB given to Docker), everything on one host, k6 included. Every run's raw data is in `results/`.

| Run | What |
| --- | --- |
| [2026-11-09-baseline](results/2026-11-09-baseline/README.md) | the defaults of v0.2.0: 4 PHP workers, the importers lock rows in the order the entries appear |
| [2026-11-09-workers](results/2026-11-09-workers/) | the authenticated route with 4, 8, 16 and 32 PHP workers |
| [2026-11-09-sorted](results/2026-11-09-sorted/README.md) | the importers lock rows in a fixed (sorted) order |

### What the baseline showed, and what was done about it

**1. The importers stopped scaling at four, and got worse at eight.** The importers update the same few account rows in the order the entries appear, so with more importers the database ends many transactions as deadlocks (700 retries per 1,000 ZIPs at eight importers, none at one), and the core's `Retry` runs them again; each detection alone costs 100 ms. The fix is in the application, not in the core: lock the rows in a fixed order first (sorted by IBAN), then update. Same 1,000 ZIPs, PgBouncer off:

| Importers | Before (rows in order of appearance) | After (sorted lock order) | Deadlock retries before → after |
|---:|---:|---:|---:|
| 1 | 78.7 s (12.7 ZIPs/s) | 78.3 s (12.8 ZIPs/s) | 0 → 0 |
| 2 | 43.5 s (23.0) | 41.0 s (24.4) | 33 → 0 |
| 4 | 32.7 s (30.6) | 21.4 s (46.6) | 116 → 0 |
| 8 | 67.1 s (14.9) | **13.9 s (72.0)** | 686 → 0 |

With the sorted order the throughput grows with every importer added (1 to 8: five and a half times), with no deadlock at all. The importer that locks in the order of appearance is still there as a choice (`lock_order=appearance`, a selector on the page), because the deadlock and the retry are worth seeing once.

**2. The authenticated read was limited by the number of PHP workers, not by the CPU.** A worker that waits for the database does nothing; four workers that each wait about 0.65 ms cannot answer more than about 6,100 requests per second. `GET /api/batches/{id}` (verified token and a database read):

| PHP workers | Saturates at (requests/s) | p95 at 7,500/s |
| ---: | ---: | ---: |
| 4 | about 6,500 | 699 ms (requests queue up) |
| 8 | about 9,600 | 62 ms |
| 16 | **about 12,400** | 5.7 ms |
| 32 | about 12,000 | 13.6 ms |

More workers than 16 bring nothing here: the CPU (about 6.6 of the 16 cores) and the database are next. The default is now 16 (`PHP_WORKERS`); the server uses about 200 MB idle and about 340 MB at full load.

**3. `GET /healthz` takes 15,000 requests per second** without an error or a dropped request (p95 7 ms); the time inside the application is a few hundred microseconds.

**4. PgBouncer costs 5 to 10 percent** of the import throughput here (one more hop). It buys connection economy, which a single-machine run does not need.

## Read these numbers with care

- Everything runs on one machine, inside Docker Desktop, sharing the CPUs with k6. It is a laptop-class measurement, not a capacity figure for a cluster.
- The import numbers include the upload: 1,000 ZIPs go up in about a second, the rest is the importers' work.
- The ratio between configurations is more reliable than any single number.
