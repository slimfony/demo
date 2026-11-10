# Does the demo's load make PHP collect garbage?

`GET /api/batches/{id}` (verified token, database read), 8,000 requests per second for 20 seconds, 16 PHP workers, one run each, the stack freshly started. The collector's runs are counted by the web server (`php_gc_runs_total`, `php_gc_collected_total`, from `gc_status()`).

| `DEFER_GC` | Requests | Collector runs | Cycles freed | p99 |
| --- | ---: | ---: | ---: | ---: |
| `true` (between requests) | 159,683 | 0 | 0 | 67.9 ms |
| `false` (PHP's own trigger) | 159,309 | 0 | 0 | 88.7 ms |

**The collector never ran, with or without `DEFER_GC`.** PHP starts it when 10,000 possible cycles have piled up; this application's requests leave almost none (the core and the controllers hold no reference cycles), so there is nothing to collect and no pause to move out of the requests. The two p99 values are one run each and differ by the noise between runs, not by the setting: no conclusion about `DEFER_GC` can be drawn from them.

So `DEFER_GC` stays off here. It is insurance for services whose code builds cycles (object graphs with parent links, closures that capture `$this` and are stored): for those, the counters show how often the collector runs, and the setting moves its pause out of the response times.
