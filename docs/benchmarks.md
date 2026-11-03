# Worst-case volume benchmark

Before trusting a design that writes on every request and aggregates in the background, I filled a local
database with the worst case and measured it. The script is [`scripts/benchmark.php`](../scripts/benchmark.php)
and it never runs in AWS.

## Scenario

- One viral link receives **90% of all clicks**; the other 10% is spread over 1,000 links.
- Every click falls **inside the 2-day window** that `stats:rollup` rebuilds on each run, so the rollup does
  the most work it can do.
- 50 referrer hosts (plus direct traffic) and 4 device classes, so a link has up to ~200 groups per day.
- The table is filled in steps of 100k, 250k, 500k and 1M clicks. At each step the script measures:
  - **Redirect DB work**: the link lookup plus the click `INSERT` (500 samples). This is everything a redirect does in the
    database.
  - **Stats from aggregates**: `ClickStats::forLink()` for 30 days (50 samples). This is what the API does.
  - **Stats from raw clicks**: the same numbers grouped straight from `clicks` (5 samples). This is the naive design
    the aggregates replace.
  - **Rollup**: one `stats:rollup` run over the 2-day window.

```bash
docker compose exec -e DB_DATABASE=testing app php -d memory_limit=1G scripts/benchmark.php
```

## Results

Measured on 2026-11-03 with Docker Desktop on Windows 11 (12 CPUs), MySQL 8.4.13 with the image default
`innodb_buffer_pool_size` of 128 MB, PHP 8.4.27.

| Clicks in window | Seed time | Rollup (2 days) | Redirect DB work p50 / p95 | Stats from aggregates p50 / p95 | Stats from raw clicks p50 / p95 |
|---:|---:|---:|---:|---:|---:|
| 100,000 | 3.4 s | 648 ms (10,273 groups) | 4.54 / 7.06 ms | 2.40 / 3.54 ms | 71.45 / 90.09 ms |
| 250,000 | 4.4 s | 977 ms (24,447 groups) | 4.54 / 6.12 ms | 2.42 / 3.54 ms | 166.23 / 173.80 ms |
| 500,000 | 7.5 s | 1,953 ms (46,651 groups) | 4.41 / 5.67 ms | 2.64 / 3.81 ms | 341.73 / 353.46 ms |
| 1,000,000 | 15.2 s | 5,294 ms (85,878 groups) | 4.61 / 12.86 ms | 2.62 / 4.52 ms | 740.16 / 971.11 ms |

## What it shows

- **Redirects do not degrade with volume.** The lookup uses the unique index on `code` and the `INSERT` appends
  to the end of the `clicks` indexes. p50 stays around 4.5 ms from 100k to 1M rows.
- **The aggregates table is worth it.** Grouping raw clicks grows linearly, to almost 1 s at p95 for 1M clicks,
  while the aggregates stay at about 2.5 ms. That cost depends on the days requested, not the traffic.
- **The rollup has a lot of headroom.** It takes 5.3 s for 1M clicks in its window and runs every 5 minutes
  with a 300 s Lambda timeout. Extrapolating linearly, and even allowing for a database a few times slower,
  the window would need tens of millions of clicks before a run gets close to the timeout.

## Caveats and what I would do next

- These are local numbers. RDS `db.t4g.micro` has 2 burstable vCPUs and 1 GB of RAM, so expect it to be
  slower in absolute terms. Its default buffer pool (about 3/4 of RAM) is larger than the 128 MB used here,
  though, which helps the scans. The *shape* of the curves is what matters: flat for redirects and aggregates,
  linear for raw stats and the rollup.
- If the rollup ever approaches its interval, the next step is to make it incremental with a high-water mark
  (aggregate only the clicks with `id` above the last processed one), instead of rebuilding the whole 2-day
  window each time.
- The redirect path was measured without the network hop to RDS (same Docker host). Inside one AZ that adds
  well under a millisecond per query.
