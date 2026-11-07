# Security policy

## Reporting a vulnerability

Email **[security@perysee.com](mailto:security@perysee.com)**. Do not open a
public issue.

Include:

- the affected version;
- the steps to reproduce;
- the impact you observed or expect;
- a proof of concept if you have one.

## What happens next

- You get an acknowledgment within **72 hours**.
- We confirm or dismiss the issue within **7 days**.
- We aim to release a fix within **30 days** for critical issues.
- We agree on a disclosure date with you. The default is **90 days** after your
  report, or the release of the fix if it comes first.
- With your permission, we credit you in the release notes.

There is no bug bounty for now.

## Scope

Reports in scope include, besides the usual web vulnerabilities:

- a way to read a site's data through a public share link (`/s/{token}`) beyond the sections its owner chose, or after the link was revoked, expired or its password changed;
- a way to guess, recover or leak a share or invitation token (both are stored as SHA-256 hashes and travel in the URL);
- a way to accept an invitation for another address, to gain a role above the invited one, or to leave a site without an owner.

## Supported versions

| Version | Security fixes |
| --- | --- |
| Latest minor release | Yes |
| Previous minor release | Yes, 3 months after a new minor release |
| Older | No |

## Modules

For a third-party module, contact its author first. If the module is on the
Perysee marketplace, also report it to the address above: we can suspend it
while a fix is prepared.
