# 003. Instance SMTP settings and a single mailer entry point

Status: proposed

## Context

Mail could only be configured through `MAIL_*` in `.env`, which many shared-hosting owners cannot or will not edit. Team invitations (ADR 002) send a message only when the mailer is not `log` or `array`, otherwise the owner copies a link. The owner of an instance also wants the same mail server for more than invitations (reports, alerts), without every feature re-implementing configuration, secrecy and error handling.

## Decision

- **Storage.** The settings are one JSON value under the key `mail_settings` of the instance state (`analytics_state`, read and written by `InstanceHealth::get/put`, the same place as the retention settings). No migration. The SMTP password is encrypted with `Crypt::encryptString` and is never returned to the browser: the screen only learns whether one is stored.
- **Precedence.** What an administrator saved and switched on wins; `.env` stays the fallback, so existing installations keep working untouched. "Use the environment instead" deletes the saved value.
- **Lazy apply.** `MailSettings::apply()` copies the settings into the mail configuration (`mail.default`, `mail.mailers.smtp.*`, `mail.from.*`), forgets the resolved mailers and is called just before a message is sent, never on every request. A change therefore takes effect at once and costs nothing on pages that send no mail.
- **Single entry point.** `App\Mail\InstanceMailer` is the only way the application sends mail: `configured()`, `send($to, $mailable)` (synchronous, no queue, 10 s timeout) and `test($to, $values)`. Features check `configured()` and fall back to their no-mail path (invitations keep the copyable link). Future mail (reports, alerts) uses the same class.
- **Errors.** A failed send throws `MailNotSent`, which carries only a category (`connection`, `authentication`, `sender_rejected`, `other`; `not_configured` for a test with nothing to test). The exception text is reported through `report()` after configured secrets, `password=` style pairs and long opaque tokens are removed, and without the original trace. The interface never shows the server's answer.
- **A password never changes server.** The stored (or environment) password is only used for the endpoint it was entered for. When host, port or user differ, or encryption is lowered to "none", and the password field is empty and "Remove the password" is not ticked, Save and Test are refused with an error on `password`. A test with unsaved values uses the typed password.
- **Access.** Administrators only (403 otherwise), CSRF as for the other forms, 5 test sends a minute per administrator.

## Consequences

- The server connects to a host chosen by an administrator: the same trust level as the database host given to the installer. There is no allow-list of hosts, so an administrator can make the instance open a TCP connection to an internal address; this is accepted for administrators and documented.
- When settings are saved for the first time with the same endpoint as `.env` and no typed password, the environment password is copied (encrypted) into the saved value, so the switch does not silently lose it.
- If `APP_KEY` changes, the stored password cannot be decrypted: it is treated as missing and has to be typed again.
- Failure classification is based on Symfony Mailer's messages and SMTP codes; an unusual server may be reported as `other`.
- The saved settings are in the database, so a backup of the database contains the encrypted password; it is only useful with the `APP_KEY`.
