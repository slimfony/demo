### Import lane: ZIPs of 10 statements, from the first upload to the last booked statement

Every configuration was run several times; the figures are the median of the runs, with the fastest and the slowest run next to it.

| Lock order | Importers | PgBouncer | Runs | ZIPs | Seconds (median, fastest-slowest) | ZIPs/s | Statements/s | Deadlock retries | Not finished |
|:---|---:|:---:|---:|---:|---:|---:|---:|---:|---:|
| sorted | 1 | no | 2 | 1000 | 78.3 (76.9-79.7) | 12.8 | 128 | 0 | 0 |
| sorted | 2 | no | 2 | 1000 | 41.0 (40.7-41.2) | 24.4 | 244 | 0 | 0 |
| sorted | 4 | no | 2 | 1000 | 21.4 (21.0-21.9) | 46.6 | 466 | 0 | 0 |
| sorted | 8 | no | 2 | 1000 | 13.9 (13.8-13.9) | 72.0 | 720 | 0 | 0 |
| sorted | 1 | yes | 2 | 1000 | 80.2 (80.1-80.3) | 12.5 | 125 | 0 | 0 |
| sorted | 2 | yes | 2 | 1000 | 43.6 (43.4-43.8) | 22.9 | 229 | 0 | 0 |
| sorted | 4 | yes | 2 | 1000 | 24.2 (22.4-26.1) | 41.3 | 415 | 0 | 0 |
| sorted | 8 | yes | 2 | 1000 | 15.1 (15.1-15.1) | 66.4 | 664 | 0 | 0 |

