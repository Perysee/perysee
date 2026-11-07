# 008. Event values, bounded properties and order de-duplication

Status: proposed

## Context

Goals (ADR-less, GOAL-01) count sessions that reach a page or an event. A shop also wants to know what those conversions are worth and how they split by payment method or price tier. The collector is public and unauthenticated, its hot path costs two queries (read the site, insert one row), and the idle scheduler has a hard budget of 30 queries (40 on MariaDB). Whatever is added must not raise either, must stay bounded against abuse and must not become a way to store personal data.

## Decision

- **Contract.** `value`, `props` and `id` are optional fields of a named event (`type=event` only; `v` stays 1). The tracker sends them only when the caller passes them: `lueur.track('purchase', { value: 49.9, props: { payment: 'card' }, id: 'order-1001' })`, also through the `push` queue. An old tracker, or an event without them, behaves exactly as before. The fields are removed from the payload before the existing whitelist check, so that check is untouched.
- **Value.** A number from 0 to 1 000 000 000, stored as thousandths in `events.event_value` (BIGINT UNSIGNED NULL). Anything else (negative, text, non-finite, on a pageview) is a 422 counted as a collection rejection. Each site has a display currency (`websites.currency`, ISO 4217, a label only: nothing is converted).
- **Goals.** The value follows the hit it belongs to and lands in the first-touch segment of the session, like `hits`: `stats_goal_daily.value_sum` and `valued_hits` (signed, summed by the existing upsert). The average is `value_sum / valued_hits`, so hits without a value do not lower it. "Conversions" counts sessions, "value" counts events: a session that orders twice is one conversion and two valued hits. It works without cookies (a hit without session keeps its own dimensions). No extra query: same rows, two more columns. The existing `totals` of the goal report are unchanged; the figures are in a new `amounts` block, and the CSV gains `value_total`, `value_average` only when a hit of the period carried a value.
- **Properties.** At most 5 per event, stored as canonical JSON (sorted keys, at most 1 024 bytes) in `events.event_props`. Key: `^[a-z][a-z0-9_]{0,31}$` after lower-casing. Value: 1 to 64 characters of text, a number or a boolean (turned into text). Structure errors (`props` not an object) are a 422. Soft limits never lose the event: surplus, invalid or oversized properties are dropped, values of keys that name a person are replaced by `:redacted`, and the response stays 204 with `X-Perysee-Truncated: props=<n>;reason=<codes>` (exposed to CORS) and a `property_truncations` counter on the health page. The tracker applies the same bounds and, with `data-debug="true"`, prints the same diagnosis in the console.
- **Privacy policy, fixed in code.** Masked keys: email, e_mail, mail, phone, tel, telephone, mobile, name, first_name, last_name, address, ip, user_id, customer_id, session, token, password, card, iban, ssn, zip, birth*, postal*. Masked values: containing `@` or a control character, 32 or more hexadecimal characters, 9 or more digits (separators allowed), or shaped like an IBAN. No property is ever stored with a session key or a visitor identifier, and the report is an aggregate by day. This is a safety net, not a guarantee: a name typed into a free field is not detected. The docs say so.
- **Aggregation.** `stats_event_property_daily`, one row per site, day, event name, key and value hash, with `metric_count`, `value_sum` and `valued_count`, filled in the aggregator's transaction (so an interrupted batch rolls back whole). Bounds, applied in the order of event ids: 100 event names per site, 10 keys per event name, 50 values per day, event and key. What exceeds a bound is counted under `(other)`, so totals stay exact. A batch without properties costs no query; with properties it costs two reads and one write per 200 rows. A hash collision is detected and fails the batch, as for the dimensions.
- **Retention.** The raw columns leave with their event (90 days). The aggregates follow the statistics retention (1 095 days) and are purged by their own hourly command, `perysee:prune-event-values`, so the idle maintenance pass costs no query more and `IdleSchedulerCostTest` is untouched. Deleting a site removes both new tables through the existing list.
- **Order identifier (`id`).** 1 to 64 printable characters, never stored: the site stores `HMAC-SHA256(app key, site : event name : id)` truncated to 128 bits in `event_order_ids (website_id, order_hash, created_at)`, with a primary key on the pair. The collector runs `INSERT IGNORE` in the event's own transaction: no read, and a duplicate (a reloaded confirmation page) is acknowledged with 204 and `X-Perysee-Duplicate: order` but not stored. A failed event insert rolls the identifier back. Identifiers are forgotten after `perysee.order_dedup_days` (30 by default, `PERYSEE_ORDER_DEDUP_DAYS`), which is also the de-duplication window. Uniqueness is per site and per event name (`purchase` and `refund` of one order do not collide).
- **Not shared.** Public share links, whose sections are a closed list (ADR 001), expose nothing of this. The report `/sites/{site}/events/properties` is for members only, its CSV goes through `CsvSafe`, which neutralises formulas (`= + - @`, tab, carriage return) in every text cell.

## Why the order identifier is not a unique key on `events`

Measured on SQLite with 300 000 events, 2 % of them orders (disposable database, same rows for each variant):

| Variant | Size | Plain insert | Order insert |
| --- | --- | --- | --- |
| A. baseline | 108.6 MB | 0.638 ms | 0.650 ms |
| B. nullable `order_hash` + unique key on `events` | 113.3 MB (+4.3 %) | 0.741 ms | 0.679 ms |
| C. side table (retained) | 109.5 MB (+0.8 %) | 0.533 ms | 1.087 ms |

Measured on MariaDB 11.4 (InnoDB, 150 000 rows, same shape, throwaway tables):

| Variant | Data | Indexes | Total | Plain insert | Order insert |
| --- | --- | --- | --- | --- | --- |
| A. baseline | 21.6 MB | 9.5 MB | 31.1 MB | 0.278 ms | 0.268 ms |
| B. unique key on `events` | 21.6 MB | 14.0 MB | 35.6 MB (+14.6 %) | 0.362 ms (+30 %) | 0.311 ms |
| C. side table (retained) | 21.6 MB (+0.27 MB table) | 9.5 MB (+0.27 MB) | 31.6 MB (+1.7 %) | 0.332 ms | 0.608 ms |

Timings are single-connection and noisy; the sizes are the reliable part. InnoDB indexes NULLs, so B pays its index entry on every row, including pageviews, on the largest table, and a random hash key on the hottest table. B needs no extra statement for an order, C needs one `INSERT IGNORE` (about +0.4 ms) for events that carry an identifier only. The request that asked for "no extra query on the collection path" is met for every event without `id`, which is nearly all of them; an event with `id` pays one write, never a read. If a deployment prefers B, the migration and the claim are the only places to change.

## Consequences

- The value and the properties are what a visitor's browser says: anyone can inflate them, as with the rest of public collection. They are indicative, not accounting.
- A rebuild or a reordered batch can admit another set of the 50 values of a day; the totals stay exact.
- Properties are not filters and not funnel steps; they only exist in the event > property report. The goal backfill reads the raw value, but the properties are not rebuilt from the raw events (no rebuild command in v1): an event aggregated before this release has no properties.
- The identifier is the only de-duplication: two different identifiers for one order, or an order older than the window, count twice.
- Migrations are additive and guarded: `events` gets two nullable columns (instant on MariaDB 10.3+, no rewrite on SQLite), `stats_goal_daily` two defaulted columns, `websites.currency` one nullable column, plus two new tables. The order table lives in the first migration because only four timestamps were reserved.
