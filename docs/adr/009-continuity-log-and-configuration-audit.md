# 009. Continuity log and configuration audit

Status: proposed

## Context

A site's figures are only comparable over time while the way they are measured stays the same. Today `site_measurement_periods` remembers one such change, the measurement mode, because the session figures depend on it. Everything else that moves the numbers is saved by `$locked->update($data)` without a trace: the consent policy (a visitor who declines is not counted), the accepted hosts, the excluded paths, the site currency, the reporting timezone. The two instance retention settings are written by `InstanceHealth::put` the same way, and shortening one removes history without anyone being told afterwards. A drop in a chart can therefore be a real drop or the day someone switched consent off, and nothing in Perysee can tell which.

Separately, the same settings can make a site silently under-count or collect more than its owner thinks (consent not required on a site with European visitors, an empty list of accepted hosts, a retention longer than the privacy notice says). Administrators today have to know where to look.

## Decision

**A continuity log.** One table, `continuity_log` (`id`, `website_id` nullable, `occurred_at` datetime(3) UTC, `kind`, `severity`, `scope`, `data_from` date nullable, `payload` JSON, `actor_id` nullable), indexed on `(website_id, occurred_at)`. A null `website_id` is an instance-level entry (a retention setting).

- **Kinds.** `tracking_mode`, `consent`, `allowed_domains`, `excluded_paths`, `currency`, `timezone` for a site; `retention_change` for the instance, and `retention_purge` when an operator purges earlier than the retention (`perysee:purge`, a `breaks` entry whose `data_from` is the cutoff date), and `storage_tier` when the storage budget moves the adaptive retention up (`breaks`) or lifts it (`note`, ADR 011). The site name and domain are not tracked: renaming changes no figure. A closed list in code, like the access log events (ADR 007); an unknown kind is refused.
- **Severity.** `breaks` when figures before and after the entry should not be compared as is, `note` when they can. Measurement mode, consent, excluded paths and currency are always `breaks`; accepted hosts are a `note` when hosts are only added and `breaks` when one is removed; a timezone change is a `note` (it is refused once an event has been collected); a retention increase is a `note`, a decrease `breaks`.
- **Scope.** The reports concerned: `all`, `sessions`, `pageviews`, `events`, `goals`, `segments`. It lets a report show a break only where it matters.
- **Payload.** Old and new values (`from`, `to`), at most 1 KiB of JSON. Text is cut to 64 characters and made valid UTF-8 first (MariaDB rejects invalid UTF-8 in a JSON column); a list keeps its first items and a `<key>_count`; if it still does not fit the items go first, then the whole payload is replaced by `{"truncated": true}`. It holds settings only: no visitor, session, address or free text.
- **One writer.** `App\Analytics\Continuity\ContinuityLog` (`record`, `diffWebsite`, `breaksSince`). `diffWebsite` compares the locked site with the attributes about to be saved and writes one entry per changed tracked field, lists compared as sets. It runs inside the transaction of the change, so a rolled back or replayed transaction leaves no entry and submitting the same form twice records the change once.
- **`site_measurement_periods` stays.** It is the source of the session coverage (`sessionCoverage`) and is read as an interval table; the log is an event list. The mode change writes both in the same transaction rather than rebuilding the periods from the log.
- **Never on the collection path.** The log is written by back-office actions only; the collector's budget (one read, one write, ADR 004 and 008) is untouched, and the idle maintenance pass gets no query: its purge is a command of its own (below).
- **Retention.** 1 095 days, like the statistics, and at most 500 rows per site (and for the instance). Over the cap, `note` entries go first, oldest first; the last entry of each kind is never removed by the cap. The hourly `perysee:prune-continuity-log` does it in bounded batches (`--limit`, 1 to 10 000, at most 20 batches a run), and an empty table costs one read. Deleting a site deletes its entries with the other site tables.
- **Retention changes.** `InstanceHealthController@update` records a `retention_change` per setting that actually changed, in the same transaction as the new value.

**A configuration audit (planned, not part of the first slices).** A page `/health/audit`, visible to instance administrators, that lists what the current settings imply.

- **A registry of checks.** Each check is a small class with an identifier, the profile it belongs to, and a pure `run(): Finding` that reads settings and counts, never events. A module can register checks through the extension registries of ADR 005. A finding has a level (`ok`, `warning`, `problem`), a short message and the setting to change; nothing is changed by the audit.
- **Profiles.** A `generic` profile, always on (a site that accepts no host, consent off with no exclusion, a retention that is far above or below the usual, the continuity breaks of the last 90 days). An optional `cnil` profile, off by default, for sites that follow the French regulator's exemption conditions for audience measurement. Its thresholds (cookie and retention durations, how long audience data may be kept, what a consent-exempt measurement may contain) are **not decided here**: they must be read on cnil.fr and validated by the maintainers before the profile is implemented, and each one will carry the date of the page it comes from. The profile helps configure; it is not legal advice and a green audit is not a compliance statement.
- **Cost.** The audit runs when the page is opened, never on a schedule and never on the collection path.

## Consequences

- Every change of a tracked setting costs one INSERT, in an admin request that already runs a locked transaction. Saving a site without changing anything costs none.
- The log answers "what changed and when", not "who was affected": it holds no figures. A report that wants to flag a break reads `breaksSince` for its window; wiring that into the reports and the API is a later slice.
- A change made directly in the database, or by an import that does not go through the controllers, leaves no entry. The log is as complete as the code paths that write it.
- The timestamp of an entry is the moment of the save; `data_from` is the local day of the site, and that day is a mix of before and after.
- The first migration creates one table, is guarded by `Schema::hasTable` and can be run again. Existing sites start with an empty log; their earlier changes are not reconstructed.
- The cap can remove old `breaks` entries when a site accumulates more than 500, by design: the log is a recent history, not an archive. The last entry of each kind survives, so the current state of every setting that ever changed stays explained.
- The audit adds a page and a registry but no table and no schedule. Its CNIL thresholds are an open point until validated against the source.
