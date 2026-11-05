# Licensing

Perysee is open source. The core in this repository is licensed under the
**GNU Affero General Public License v3.0 only**, with an additional permission
called the **Perysee Module Exception**.

| Part | License | File |
| --- | --- | --- |
| Perysee core (this repository) | AGPL-3.0-only + Perysee Module Exception | [LICENSE](LICENSE), [MODULE-EXCEPTION.md](MODULE-EXCEPTION.md) |
| Module SDK (`perysee/module-sdk`) | MIT | `LICENSE` in that repository |
| Official Perysee modules | Perysee Commercial Module License | [module.perysee.com/legal](https://module.perysee.com/legal) |
| Third-party modules | Chosen by each module author | In each module |
| The Perysee name and logo | Not licensed | [TRADEMARKS.md](TRADEMARKS.md) |

## What you can do

- **Use Perysee for free**, on as many sites as you want, for any purpose, commercial included.
- **Modify it.** If you run a modified version and let people use it over a network
  (a hosted service, a client dashboard), you must offer them the source code of
  your modified version. This is section 13 of the AGPL.
- **Redistribute it**, modified or not, under the AGPL.
- **Build modules** through the public Module API and license them however you
  want, including closed source. The Module Exception gives you this right.
- **Sell your modules** wherever you want. The Perysee marketplace has its own
  rules, but they only apply to modules published there.

## What you cannot do

- Turn the core into closed source. Any change to the core stays AGPL.
- Call a modified version "Perysee", or present your product or module as
  official or verified by Perysee. See [TRADEMARKS.md](TRADEMARKS.md).
- Use the Module Exception for a module that bypasses license checks or relies
  on internal APIs. Such a module falls back under the plain AGPL.

## Source link (AGPL section 13)

The Perysee admin interface shows a "Source code" link in its footer.
If you run an unmodified release, the link to this repository is enough.
If you modify the core, point that link to your own published source.

## Contributing

Contributions to the core require signing the [Contributor License Agreement](CLA.md).
It lets the project keep granting the Module Exception and fix licensing issues
later. In return, the CLA commits the project to keep every accepted contribution
available under an open source license.

## File header

Every source file of the core starts with:

```
/**
 * This file is part of Perysee.
 *
 * Copyright (C) 2026 Matteo Ribardière
 * SPDX-License-Identifier: AGPL-3.0-only WITH AdditionRef-Perysee-Module-Exception
 *
 * Perysee is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3, with the
 * Perysee Module Exception. See LICENSE and MODULE-EXCEPTION.md.
 */
```
