# Architecture decision records

Decisions that shape the code and that a contributor would otherwise have to rediscover. Each record is numbered, written in English and states its status: `proposed` until the code is merged into `develop`, then `accepted`; a later record can supersede it.

| No. | Decision | Status |
| --- | --- | --- |
| [001](001-public-share-links-separate-shell.md) | Public share links use a separate, minimal read-only shell | proposed |
| [002](002-invitation-tokens-and-the-no-mail-path.md) | Invitation tokens and the no-mail path | proposed |
| [003](003-instance-smtp-settings.md) | Instance-level SMTP settings and a single mail entry point | proposed |
| [004](004-tracker-installation-check.md) | Tracker installation check: typed collector refusals and a signed test | proposed |
| [005](005-module-events-and-extension-registries.md) | Module events and extension registries (notification channels, report sources) | proposed |
| [006](006-read-only-api-tokens.md) | Read-only API tokens, in-house, one token per site | proposed |
| [007](007-two-factor-authentication-open-sessions-and-the-access-log.md) | Two-factor authentication, open sessions and the access log | proposed |
| [008](008-event-values-and-bounded-properties.md) | Event values, bounded properties and order de-duplication | proposed |
| [009](009-continuity-log-and-configuration-audit.md) | Continuity log and configuration audit | proposed |

To add one, copy the structure of an existing record (Context, Decision, Consequences), take the next number and update this table.
