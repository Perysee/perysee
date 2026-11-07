# 006. Read-only API tokens, in-house, one token per site

Status: proposed. Implemented on `feat/read-api-tokens`; it becomes accepted once merged into `develop`.

## Context

WordPress plugins, Looker Studio connectors and server-side widgets need the same figures as the interface and the CSV exports, without a session. The contract has to be versioned and stable, because modules and plugins will depend on it. Share links (ADR 001) already give anonymous read access to a few reports, but they are meant for people, carry no filters and have no machine-readable contract.

## Decision

**In-house tokens, no Sanctum or Passport.** Sanctum ties a token to a user (polymorphic `tokenable`), has free-form abilities, writes `last_used_at` on every request and adds a migration, a config file and a cookie-based SPA surface; it would also be a new Composer package to ship in the release archive. Passport is OAuth, which is out of scope. `ApiToken` follows `ShareLink` (issue and hash) and `ResolveApiToken` follows `ResolveShareLink` (lookup), about 250 lines and no dependency.

**Token.** `psy_` followed by 32 random bytes in base64url (the prefix lets secret scanners recognise it). Only the SHA-256 is stored (`api_tokens.token_hash`, unique), with the last six characters as a hint for the owner's list. The secret is shown once, through a one-shot session flash, on a page served `Cache-Control: no-store`. A token belongs to exactly one site and carries its own scopes, a rate limit and an optional expiry. It survives its creator leaving the site (`created_by` is set to null); revoke it by hand if that is not wanted. Only a site owner creates, lists or revokes tokens (`WebsitePolicy::manageApiTokens`); an instance administrator has no implicit access, and demonstration sites cannot have tokens.

**The token carries the site.** URLs contain no site identifier (`/api/v1/overview`), so there is nothing to enumerate and no object-level authorisation to forget. Like share links, the site is put on the request by the middleware, never through route-model binding (ADR 001); goal and funnel ids are always queried together with the site id, so another site's id gives the same 404 as an unknown one.

**Transport.** `Authorization: Bearer` only. A token in the query string or the body is ignored, so it cannot end up in logs. Routes (`routes/api_v1.php`) are outside the `web` group: no session, no cookie, no CSRF. `ApiV1Boundary` is the outermost global middleware and gives every response of `/api/v1/*` the same headers (`Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag`, `X-Content-Type-Options`, `X-Perysee-Api-Version: 1`).

**No CORS.** A bearer token in browser JavaScript is public, so browsers are meant to go through a share link or a server-side proxy. The framework's default CORS configuration allows every origin for `api/*`, and the tracker relies on it for `/api/collect`. Rather than changing that global default, `ApiV1Boundary` answers an `OPTIONS` request on `/api/v1/*` itself (405, no `Access-Control-*`) and strips any `Access-Control-*` header from the other responses. A test pins both behaviours, and that `/api/collect` keeps its own.

**Errors.** One envelope, `{"error": {"code", "message", "details"}}`, rendered for every exception raised under `/api/v1`: 401 `invalid_token` (identical body for absent, unknown, malformed, over-long, expired, revoked, deleted-site and demo-site tokens), 403 `insufficient_scope` (with `details.required`), 404 `not_found`, 404 `report_not_computed`, 405, 422 `validation_failed`, 429 `rate_limited`, 503, 500 (a fixed message, never SQL or trace).

**Scopes.** Nine: `overview`, `pages`, `sources`, `campaigns`, `devices`, `countries`, `events`, `goals`, `funnels`. `ReportSections` is the single map from a section to the dimension reports it opens; share links use its first seven, the API all nine. A scope also governs the **filters** a request may use: filtering by country with a token that cannot read countries would reveal a breakdown by country, so it is a 403 (`provider`, `source_group` and the AI-source options need `sources`). The `goals` scope includes the conversion breakdown by source, campaign, country and device that the goal screen shows.

**One source of figures.** The API reuses `Reports`, `GoalReports` and `JourneyReports`; it never recomputes anything. Serialisation goes through allow-lists in `ReportPresenter`, shared with share links. The JSON of share links is pinned by `ShareJsonCharacterizationTest`, so that extraction changed nothing. The comparison block carries the server's verdict (`reliability.<metric>.percent_allowed` and `reason`): a client never computes a percentage.

**Limits.** Tables are capped at 100 rows (`meta.limit`, `meta.truncated`, and `distinct_values` for the true count), like the CSV exports. The period rules are those of the interface (three years at most, never in the future, hourly granularity for one day). Each token has 60 requests a minute by default, between 10 and 300 as its owner chooses (`perysee.api`), counted with `RateLimiter` (the cache of the application, not `Coordination`, which cannot report `remaining` and `reset`); `X-RateLimit-*` is on every authenticated response and `Retry-After` on 429. Failed authentication is limited per hashed client address through `Coordination::throttle`, as for share links; a client with a good token is never limited by it.

**Funnels are read-only.** A funnel report is an asynchronous job that inserts rows. `GET /funnels/{id}` returns the newest finished result for exactly that version, period and filters, or 404 `report_not_computed`; it never starts a calculation. Internal columns (`cache_key`, `snapshot_id`, `session_key`, `cursor`, `state`, `payload`) are not part of any response.

**Usage and retention.** No per-request table. `last_used_at` and `use_count` are updated by one conditional `UPDATE` at most every 10 minutes per token (so `use_count` counts windows of use, not requests). Tokens expired or revoked for more than 30 days are deleted by `perysee:prune-api-tokens`, hourly and without overlap. It is a dedicated command on purpose: the idle maintenance pass has a query budget (`IdleSchedulerCostTest`) that an extra purge would eat into. Tokens disappear with their site (foreign key with cascade).

**Description.** `GET /api/v1/openapi.json` serves `resources/openapi/v1.json` (OpenAPI 3.1, written by hand, public). A test fails when a route of `api_v1.php` is missing from it, or the other way round.

**Versioning.** `/api/v1` only gets additions of fields. Anything else needs `/api/v2`.

**Modules (MOD-03).** The Core API is not a module and is not subject to `ModuleScope`. For later: a module route would reuse `ResolveApiToken`, declare scopes prefixed `module:<id>.<name>` and check `ModuleScope::isActiveFor`. `EnsureModuleActiveForSite` only reads the `website` route parameter today and would have to read `api_site` too. This is not implemented.

## Consequences

- No new dependency and nothing to install on the client's server.
- The secret is bearer-only and long-lived: expiry, revocation, scopes and the per-token limit are the mitigations. It must be kept on a server.
- `Reports::period` validation now backs a public contract as well as the interface; the tests of the exact keys of every response (and the OpenAPI drift test) catch a change that breaks version 1.
- A funnel nobody opened in the interface cannot be read by API (404 `report_not_computed`); this is documented.
- The rate limit uses the application cache; with a cache shared by several servers it is shared too, with a non-atomic window on the file driver (a few extra requests under a burst).
