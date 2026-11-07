# 001. Public share links use a separate, minimal read-only shell

Status: proposed. Implemented on the integration branch under review; it becomes accepted once merged into `develop`.

## Context

An owner can share a read-only view of one site's reports through a link, without an account. The authenticated interface (`Workspace.vue`, `WorkspaceLayout.vue`, `ReportPage.vue`) is built around props that must never reach an anonymous visitor: `auth`, `navigation` (every site of the user), `links`, `modules`, `branding`, and page props such as `website` (with its `public_id`), `siteLinks` and `canUpdate`.

## Decision

Public pages are separate Inertia pages (`Pages/Share/Show.vue`, `Unlock.vue`) that do not use `Workspace.vue` or its layout. They reuse only presentational components that take their data as props (`TrafficChart`, `BreakdownTable`, `DateRange`, `ColorModeButton`).

On the server:

- Routes live in `routes/share.php`, behind the `web` group, `EnsureInstalled` and `ResolveShareLink`. The link and its site are put on the request by that middleware, never through route-model binding.
- `HandleInertiaRequests::share` returns only `currentUrl`, `assets`, `flash` and `errors` for routes named `share.*`. A test asserts the exact set of keys.
- `PublicShareController` builds an explicit allow-list of props (site name, domain, timezone, period, overview totals, series, the dimension reports the link allows). Anything not listed is absent, so a future change to the authenticated screens cannot leak into the public one.
- Every unusable link (unknown, revoked, expired, site deleted, demonstration site) gets the same static 404, for pages and JSON alike. Misses are throttled per client address (60 a minute); a valid link is never limited by this.
- The token is 32 random bytes (base64url). Only its SHA-256 is stored (`share_links.token_hash`, unique) with a six-character hint for the owner's list; the full link is shown once, right after creation. Only a site owner creates, changes or revokes links (`WebsitePolicy::share`).
- A link exposes the sections picked by the owner (`overview`, `pages`, `sources`, `campaigns`, `devices`, `countries`, `events`), an optional expiry date and an optional password. The visitor can choose a period, a granularity and one dimension among those the link allows, and nothing else: filters, site identifiers and exports are not read.
- A password is checked with `Hash::check`, limited to 5 attempts per link in 15 minutes and 20 per address in an hour. Setting or removing the password increments `secret_version`, which signs out every visitor who had unlocked the link.
- Every response carries `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow`. Use is recorded at most once an hour per link (`last_used_at`, `use_count`).

## Consequences

- Reusing `ReportPage.vue` would have saved code but coupled the public surface to authenticated props: any prop added there would be public by default. The separate shell fails closed instead.
- Some layout and markup is duplicated in `Show.vue`. This is accepted.
- The token is part of the URL, so it can end up in browser history or in a proxy log. Passwords, expiry and revocation are the answers to that; the link is a bearer credential. No expired or revoked link is purged: the rows stay until the site is deleted.
- `app.js` resolves `Share/*` pages directly and every other page through `Workspace.vue`.
- Reused components read `usePage().props.currentUrl` and `assets`, which the minimal shared set keeps providing. A component added to the public page must not require any other shared prop.
