# 001. Public share links use a separate, minimal read-only shell

Status: proposed

## Context

An owner can share a read-only view of one site's reports through a link, without an account. The authenticated interface (`Workspace.vue`, `WorkspaceLayout.vue`, `ReportPage.vue`) is built around props that must never reach an anonymous visitor: `auth`, `navigation` (every site of the user), `links`, `modules`, `branding`, and page props such as `website` (with its `public_id`), `siteLinks` and `canUpdate`.

## Decision

Public pages are separate Inertia pages (`Pages/Share/Show.vue`, `Unlock.vue`) that do not use `Workspace.vue` or its layout. They reuse only presentational components that take their data as props (`TrafficChart`, `BreakdownTable`, `DateRange`, `ColorModeButton`).

On the server:

- Routes live in `routes/share.php`, behind the `web` group, `EnsureInstalled` and `ResolveShareLink`. The link and its site are put on the request by that middleware, never through route-model binding.
- `HandleInertiaRequests::share` returns only `currentUrl`, `assets`, `flash` and `errors` for routes named `share.*`. A test asserts the exact set of keys.
- `PublicShareController` builds an explicit allow-list of props (site name, domain, timezone, period, overview totals, series, the dimension reports the link allows). Anything not listed is absent, so a future change to the authenticated screens cannot leak into the public one.
- Every unusable link (unknown, revoked, expired, site deleted) gets the same static 404, for pages and JSON alike.

## Consequences

- Reusing `ReportPage.vue` would have saved code but coupled the public surface to authenticated props: any prop added there would be public by default. The separate shell fails closed instead.
- Some layout and markup is duplicated in `Show.vue`. This is accepted.
- `app.js` resolves `Share/*` pages directly and every other page through `Workspace.vue`.
- Reused components read `usePage().props.currentUrl` and `assets`, which the minimal shared set keeps providing. A component added to the public page must not require any other shared prop.
