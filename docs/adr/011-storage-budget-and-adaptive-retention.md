# 011. Storage budget and adaptive retention

Status: proposed

## Context

On shared hosting the database has a disk quota and nothing warns an administrator before it is full (the quota projection of the Health page only predicts a date). The remedy today is to lower the retention settings by hand, which rewrites a decision the administrator made for other reasons and is rarely undone. The purges that already exist (raw events, daily and hourly aggregates, ADR 010 for the session registries) follow the retention settings, so the lever exists; what is missing is a way to pull it temporarily, in steps, only with consent, and to put it back.

## Decision

**Settings.** Two states in `analytics_state`, read and written through `InstanceHealth`: `storage_budget_bytes` (absent = no budget) and `storage_mode`: `off` (default), `ask` or `auto`. The size compared with the budget is the one `StorageMeter` reports (the engine's own statistics, an estimate). The Health page enters the budget in MiB, 16 TiB at most; a mode other than `off` needs a budget.

**Tiers, computed and reversible.** A tier is a stored number (`storage_tier`, with `storage_tier_since`), never a retention setting.

| Tier | Effect |
| --- | --- |
| 0 | Normal: the administrator's settings. |
| 1 | Raw events kept at most 30 days. |
| 2 | Raw events kept at most 7 days. |
| 3 | Hourly aggregates (`stats_hourly`, `stats_segment_hourly`) kept at most 90 days. |
| 4 | Hourly aggregates kept at most 30 days. |

The effective value is `min(setting, cap)`, so a tier can only shorten a retention and never lengthens one (`StorageTiers`). The daily aggregates and the session registries are never touched. The plan named three tiers and said the hourly aggregates go to "90 then 30 days": that makes two steps, hence a fourth tier.

**Moving between tiers.** Only the hourly command `perysee:storage-budget` moves a tier. At 100 % of the budget or more it goes up one tier, and the next one only after a day (`ESCALATION_HOURS`), so that each tier has time to free space. Under 80 % all tiers are lifted at once; between 80 % and 100 % the tier holds (no flapping). Switching the mode to `off`, or removing the budget, lifts the tier at the next pass. With no budget an idle pass costs one read; with one it adds one measurement of the engine's statistics and one upsert of the last figure (`storage_budget_state`), which the home page reads instead of measuring.

**Modes.**
- `off`: no action; only the Health alert (`storage_budget`) says the budget is exceeded.
- `ask`: the alert carries a button. `PUT /health/storage/agreement` (administrators, `tier` validated against the tier actually proposed, so nobody agrees to a deeper cut than the page showed) stores the agreement; the next hourly pass applies that tier. Each step needs its own agreement, and the agreement is forgotten when the database is back under 80 %.
- `auto`: the administrator chose it explicitly. Both the choice and every change of budget or mode are written to the access log (`storage.settings`; an agreement is `storage.agreed`).

**One access point for the purges.** `InstanceHealth::effectiveRetention()` returns the raw, statistics and hourly retention with the tier applied, in one read. `perysee:prune-tracking`, `perysee:maintenance` (hourly aggregates cut at their own, shorter date) and `perysee:prune-sessions` read it, and so does `perysee:purge` to decide whether a manual purge is shorter than the retention. `retentionDays()` stays the administrator's setting, which the retention form edits and the continuity log compares. Every purge keeps its guards: only processed events, aggregates and registries kept while an older unprocessed event waits, the aggregation lock.

**Continuity.** Each tier change writes a `storage_tier` entry (instance level): `breaks` when the tier goes up (data now disappears earlier), `note` when it is lifted. The payload holds the old and new tier, the percentage and the mode.

**Domain event `ingest.storage_budget_exceeded`.** `StorageBudgetExceeded`, schema 1, fields `tier` (int), `ratio_percent` (int), `days_left` (int or null: the quota projection, when a quota is entered and a date is projected), no site. It is published by `perysee:storage-budget`, after the tier is committed and outside any transaction, once per upward transition: a marker row per tier (`domain_events:storage_budget:<tier>`, claimed atomically like the backlog marker of ADR 005) prevents a repeat, and all markers are released when the tier returns to 0. In `off` and in `ask` before the agreement no tier moves, so nothing is published; the Health alert covers those. The collector never publishes (`DomainEventCriticalPathTest` covers the new event too).

**Module API.** The event exists from Perysee 0.5.0, later than `ModuleApi::DOMAIN_EVENTS_SINCE` (0.4.0). The event class carries `SINCE = '0.5.0'` and `ModuleProvider::listen()` refuses a module whose `core` is lower, with the same message as for the bus itself. An older Core does not have the class and refuses a module that requires 0.5.0 when it is enabled. `config/perysee.php` is not bumped by this change; the release will.

## Consequences

- A purge is as fast as its schedule: raw events and hourly aggregates go one batch per five-minute maintenance pass (1 000 rows each by default), so a large backlog takes hours to disappear after a tier rises. An operator in a hurry uses `perysee:purge`.
- Reports at hourly granularity for a period older than the hourly retention are empty under tier 3 or 4, and journey reports and goal backfills see fewer raw events under tiers 1 and 2. The Health page and the alert say which retention applies.
- The tier follows an estimate (`information_schema` on MariaDB, `dbstat` on SQLite); space freed by deleting rows is only given back to the disk after `OPTIMIZE TABLE` (`perysee:storage-optimize`), so the measured size can stay above the budget for a while and the next tier will be proposed after a day. This is why `ask` shows the percentage and why `OPTIMIZE` is never scheduled.
- Lifting a tier gives the administrator's settings back; deleted data does not return.
- Tests to replay on MariaDB: `StorageBudgetTest`, `EffectiveRetentionTest` (the `LIKE` marker delete and the `whereIn` state read), `StorageBudgetSettingsTest`.
