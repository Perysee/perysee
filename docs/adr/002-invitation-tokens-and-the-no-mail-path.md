# 002. Invitation tokens and the no-mail path

Status: proposed

## Context

No route created users or added members to a site. Perysee must run on shared hosting where mail is often not configured (the default mailer is `log`) and where no worker runs permanently. Site roles live in `site_members` (`viewer` or `owner`).

## Decision

- A site owner invites an e-mail address with a role. Only owners can invite, change roles, remove members or revoke; an instance administrator has no implicit access.
- The invitation token is `random_bytes(32)` in hex. Only its SHA-256 is stored (`site_invitations.token_hash`, unique). The raw token exists in the link only.
- Without a working mailer (mailer `log` or `array`, or sending throws), the invitation is kept and the full link is shown once to the owner, with a copy button. "Resend" rotates the token and shows a new link; the previous link stops working. With a mailer, a synchronous `Mailable` is sent (no queue); `last_sent_at` and the verification of the new account's address depend on a message having really left.
- The invitation is bound to its e-mail (lowercase) and valid 7 days. One pending invitation per site and e-mail, enforced under a lock on the site row.
- `GET /invitations/{token}` never consumes the token (mail scanners open links). The `POST` does, in a transaction with `lockForUpdate`. Any non-pending token (unknown, expired, revoked, used) gets the same 404. The pages are sent with `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.
- A person without an account creates one by accepting (name and password, never an administrator). A person with an account must be signed in with the invited address, otherwise they get a generic message that does not reveal the invited address. Nothing is merged across e-mails.
- Limits: 20 invitations an hour per user, one resend a minute per invitation, 10 requests a minute per address on the link, and five failed acceptances close an invitation.
- A site always keeps one owner: role changes, removals and leaving run under the site row lock and refuse (422) to remove the last owner.
- Expired and settled invitations older than 30 days are purged by `perysee:maintenance`.

## Consequences

- Whoever holds a valid link learns that an account exists for the address (they are sent to sign in). The link is only sent to the invited address, or by the owner.
- The link is visible in the owner's session for one request. If it is lost, the owner resends to get a new one.
- No migration touches `site_members` or `users`.
