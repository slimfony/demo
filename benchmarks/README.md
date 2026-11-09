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

| Run | When | |
| --- | --- | --- |
| [2026-11-09-baseline](results/2026-11-09-baseline/README.md) | before any tuning | i5-14400, 16 CPUs and 8 GB for Docker, one machine for everything |

What the baseline shows:

- **`GET /healthz`: 15,000 requests per second without an error or a dropped request** (p95 7 ms at 15,000, 2.4 ms at 10,000). The time spent inside the application is a few hundred microseconds; the rest is the web server and the network stack.
- **`GET /api/batches/{id}` (token and database read) saturates at about 6,300 requests per second**, while the web container uses only about 3.6 of the 16 cores. The limit is not the CPU: four PHP workers that each wait about 0.65 ms for the database cannot answer more than 4 / 0.00065 = about 6,100 requests per second. Workers that mostly wait should outnumber the cores.
- **The importers scale from 1 to 4, then get worse**: 1,000 ZIPs take 83 s with one importer, 45 s with two, 35 s with four, and 70 s with eight. The importers update the same few account rows in the order the entries appear, so with more importers the database ends many transactions as deadlocks (700 retries per 1,000 ZIPs at eight importers, 0 at one) and the core's `Retry` runs them again. The deadlock detection alone costs 100 ms each time.
- **PgBouncer costs 5 to 10 percent** of the import throughput here (it is one more hop); it buys connection economy, which a single-machine run does not need.

## Read these numbers with care

- Everything runs on one machine, inside Docker Desktop, sharing the CPUs with k6. It is a laptop-class measurement, not a capacity figure for a cluster.
- The import numbers include the upload: 1,000 ZIPs go up in about a second, the rest is the importers' work.
- The ratio between configurations is more reliable than any single number.
