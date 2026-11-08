# 010. Session purge beyond the statistics retention

Status: proposed

## Context

Two registries grow with the number of sessions and never shrink while the site exists. `measured_sessions` (one row per session: first and last time it was seen) lets the aggregator count a session once and move the counters of the right day when an earlier event turns up late. `segment_sessions` (one row per session: its first event and the dimensions computed from it) does the same for the cross-filter counters (`Segments::ingest`). On a busy site they are the largest tables after `events`, and unlike every other table they have no retention at all: they were kept "until the site is deleted", a growth with no bound on shared hosting.

The registries are only read to *correct* a count: when an event older than the session's recorded first time arrives, the session start moves to the older event, and the counters of both days are adjusted. Raw events are kept 90 days by default and the statistics 1 095 days; an event is consolidated minutes after it is collected, unless the scheduler stops for a long time. A registry row older than the statistics retention can therefore only be needed for an event that is still waiting to be consolidated, or for a rebuild over raw events older than the statistics, which would recreate counters of days that have been purged anyway.

Other kinds of rows were also never purged: public share links that expired or were revoked (SOC-04), expired entries of the `file` cache store, and failed queue jobs.

## Decision

The product owner approved that sessions beyond the statistics retention are no longer recountable.

- **Rule.** `perysee:prune-sessions` deletes the rows of `measured_sessions` (by `last_seen_at`) and `segment_sessions` (by `first_seen_at`) older than the statistics retention (`stats_retention_days`, 1 095 by default) plus one UTC day, the same conservative cutoff as the statistics purge so that the local retention day of every site is preserved.
- **Guard.** Nothing is deleted while an unprocessed event (`processed_at` null) older than the cutoff exists: the same `$statsLocked` rule as the statistics purge, now in one class (`App\Analytics\Maintenance\RetentionPurge`) that the scheduled commands and the operator's `perysee:purge` (tranche 4) share. A recent unprocessed event does not hold the purge back.
- **Lock.** The purge takes the named aggregation lock (`perysee:aggregate:<hash of the database name>`) around its deletions, like `perysee:prune-tracking`, so it never runs in the middle of a consolidation batch.
- **Own schedule.** The command is hourly and without overlapping, not part of the maintenance pass, whose idle cost is capped (`IdleSchedulerCostTest`). An idle pass costs two reads (the retention setting, then one query that asks both tables whether anything is old enough). A run deletes at most 20 batches of `--limit` rows (1 to 10 000, 1 000 by default) per table, taken oldest first through an index.
- **Index.** One additive migration indexes `segment_sessions.first_seen_at`; `measured_sessions.last_seen_at` already is. Without it every batch would read the table in primary key order. The migration is guarded by `Schema::hasIndex` and can be run again.
- **Other purges of the same slice.** `perysee:prune-share-links` deletes links that expired or were revoked more than 30 days ago (a revoked or expired link already answers with the same 404 page, so nothing visible changes). `perysee:prune-cache` deletes expired files of the `file` cache store, which never removes an entry unless it is read again and which the installer configures: the rate limiter makes one file per key, and shared hosting counts inodes. It reads only the 10-digit expiry prefix of files named like the store names them, never touches directories, and examines at most 50 000 files per run, starting from a different place every hour.
- **Manual purge.** `perysee:purge <target> --older-than=<age>` (targets `events`, `stats`, `segments`, `sessions`, `share-links`) lets an operator purge earlier than the retention. It is a simulation unless `--force` is given and confirmed (or `--yes`), it takes the same `RetentionPurge` rules and the aggregation lock, refuses an age under one day, and when it is shorter than the retention in force it writes a `retention_purge` entry (`breaks`) in the continuity log.
- **Not done.** `failed_jobs`: the application dispatches no job, the installer sets the `database` queue connection but nothing runs a worker, and the scheduler never calls `queue:work`; the table cannot fill from the Core. A module that queues work can add its own purge.

## Consequences

- A session older than the statistics retention plus one day cannot be recounted. A later rebuild over raw events that are older than that (only possible if the raw retention was set above the statistics retention) would count those sessions again in days whose statistics are already gone.
- The registries stop growing without bound: their size follows the statistics retention, and the retention changes (including the adaptive ones of ADR 011) apply to them.
- A stopped scheduler keeps the registries and the statistics together: the guard holds both back for as long as an old unprocessed event exists, and both resume once it is consolidated.
- A purged share link cannot be listed or restored; the access log keeps the record of its creation for 90 days.
- Expired cache files are removed by a process that does not coordinate with the cache writers: a file rewritten between the expiry check and the delete is lost, which costs one cache miss, as when Laravel itself deletes an expired entry it reads.
- Tests to replay on MariaDB: `SessionPurgeTest` (index creation, row-value `IN` deletes, the one-query idle check with `UNION ALL` of limited selects) and `BatchedPurgeTest`.
