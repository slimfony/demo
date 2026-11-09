### API lane: GET /healthz

| Target rate (req/s) | Achieved (req/s) | Dropped | Errors | median ms | p95 ms | p99 ms | max ms |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 1000 | 1,000 | 0 | 0.00% | 0.5 | 1.4 | 2.2 | 14.0 |
| 2500 | 2,500 | 0 | 0.00% | 0.4 | 1.0 | 1.5 | 16.9 |
| 5000 | 5,000 | 0 | 0.00% | 0.4 | 0.9 | 1.9 | 17.0 |
| 7500 | 7,500 | 0 | 0.00% | 0.5 | 2.4 | 10.1 | 91.2 |
| 10000 | 10,000 | 0 | 0.00% | 0.5 | 2.4 | 6.9 | 31.5 |
| 12500 | 12,500 | 0 | 0.00% | 0.5 | 3.7 | 9.8 | 37.1 |
| 15000 | 15,000 | 0 | 0.00% | 0.5 | 7.0 | 19.7 | 55.9 |

### API lane: GET /api/batches/{id}, verified token and a database read

| Target rate (req/s) | Achieved (req/s) | Dropped | Errors | median ms | p95 ms | p99 ms | max ms |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 1000 | 1,000 | 0 | 0.00% | 0.9 | 2.3 | 3.6 | 9.1 |
| 2500 | 2,500 | 0 | 0.00% | 0.8 | 2.9 | 11.0 | 32.9 |
| 5000 | 5,000 | 0 | 0.00% | 1.0 | 16.8 | 40.8 | 61.4 |
| 7500 | 6,273 | 0 | 0.00% | 582.4 | 671.7 | 741.9 | 765.0 |
| 10000 | 6,114 | 0 | 0.00% | 654.1 | 867.6 | 962.2 | 1,015.7 |
| 12500 | 5,797 | 0 | 0.00% | 655.0 | 888.8 | 926.6 | 942.6 |
| 15000 | 6,367 | 0 | 0.00% | 625.4 | 738.2 | 866.5 | 875.5 |

### Import lane: ZIPs of 10 statements, from the first upload to the last booked statement

Every configuration was run several times; the figures are the median of the runs, with the fastest and the slowest run next to it.

| Importers | PgBouncer | Runs | ZIPs | Seconds (median, fastest-slowest) | ZIPs/s | Statements/s | Deadlock retries | Not finished |
|---:|:---:|---:|---:|---:|---:|---:|---:|---:|
| 1 | no | 3 | 1000 | 78.7 (77.3-79.2) | 12.7 | 127 | 0 | 0 |
| 2 | no | 3 | 1000 | 43.5 (43.0-44.8) | 23.0 | 230 | 33 | 0 |
| 4 | no | 3 | 1000 | 32.7 (31.7-33.4) | 30.6 | 306 | 116 | 0 |
| 8 | no | 3 | 1000 | 67.1 (66.3-71.1) | 14.9 | 149 | 686 | 0 |
| 1 | yes | 3 | 1000 | 83.0 (83.0-84.7) | 12.0 | 120 | 0 | 0 |
| 2 | yes | 3 | 1000 | 45.7 (45.1-45.8) | 21.9 | 219 | 31 | 0 |
| 4 | yes | 3 | 1000 | 36.8 (34.9-37.9) | 27.2 | 272 | 127 | 0 |
| 8 | yes | 3 | 1000 | 70.8 (69.1-79.5) | 14.1 | 141 | 722 | 0 |

