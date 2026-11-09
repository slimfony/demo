# Messages per pull request

One importer pulled one message per request from NATS. The new `import_stage_seconds` histogram showed where the time of a ZIP went: about 26 ms writing, 4 ms parsing, 2 ms each for claim and events and 1.5 ms finishing. These add up to 35 ms, but a ZIP took 79 ms: **about 41 ms were spent outside the import, waiting for the broker** (the pull and the acknowledgement), more than half of the wall time. Pulling several messages per request pays that wait once for all of them (`IMPORT_PULL_BATCH`, `BENCH_PULL_BATCH` in the benchmark).

400 ZIPs, PgBouncer on, sorted locking. The lines of `import.jsonl` are, in order:

| Importers | ZIPs | Messages per pull | Seconds | ms per ZIP |
| ---: | ---: | ---: | ---: | ---: |
| 1 | 400 | 1 | 31.7 | 79 |
| 1 | 400 | 5 | 15.8 | 40 |
| 1 | 400 | 10 | 14.1 | 35 |
| 1 | 400 | 20 | 14.2 | 35 |
| 8 | 1000 | 1 | 13.9 | 14 |
| 8 | 1000 | 5 | 13.0 | 13 |

The 35 ms floor is the import itself. With eight importers the stalls of the different importers overlap and the database write is the limit, so the gain is small. The default is 5: most of the gain, and a killed importer holds at most five unacknowledged messages (the acknowledgement wait gives them back).
