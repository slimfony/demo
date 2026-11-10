# Fewer database round trips per statement

A statement in the default mode (sorted locking) took eight round trips to PostgreSQL: begin, lock the accounts, lock the statistics, insert the statement, insert its entries, update the balance, update the statistics, commit. Two pairs now go together: the statement and its first entries are one data-modifying `WITH` statement, and the balance and the statistics are one `WITH ... UPDATE`. The lock queries stay separate, in their fixed order, because the order is what prevents deadlocks. That is six round trips.

PgBouncer on, sorted locking. Before: the figures of 2026-11-09 (`../2026-11-09-pull-batch/`).

| Importers | ZIPs | Before | After (two runs) |
| ---: | ---: | ---: | ---: |
| 1 | 400 | 15.8 s | 14.9 s (first run after the start), 12.8 s |
| 8 | 1000 | 13.0 s | 11.5 s, 11.7 s |

About 10 to 20 percent. The second run with one importer is the steady state; the first one pays for the start (opcache, connections). With eight importers the database is the limit, and fewer round trips are less work for it and for PgBouncer. The runs are few: read the direction (a little faster, no deadlocks, nothing failed), not the exact percentage.
