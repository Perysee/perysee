# Perysee Module Exception

Version 1.0, November 2026

This exception is an additional permission under section 7 of the GNU Affero
General Public License, version 3 ("AGPLv3"). It is granted by the copyright
holder of Perysee and applies to every version of Perysee that ships with this
file.

## 1. Definitions

**"Perysee"** means the program distributed in this repository under AGPLv3.

**"Public Module API"** means the interfaces, hooks, events, routes and
extension points that Perysee documents as public for modules in
`docs/modules/api.md`, together with the Module SDK published under the MIT
license.

**"Module"** means a work that:

1. is loaded by Perysee through its module system;
2. interacts with Perysee only through the Public Module API; and
3. does not contain any part of Perysee's source code other than the Module SDK.

## 2. Permission

As a special exception, the copyright holder gives you permission to:

- combine Perysee with one or more Modules;
- run, convey, or make available that combination over a network; and
- license, distribute and sell each Module under terms of your choice,
  including proprietary terms,

whether or not a Module might otherwise be considered a work based on Perysee
under copyright law.

Perysee itself, and any modification of it, remains under AGPLv3. This
exception does not remove any of your obligations regarding Perysee's own code.

## 3. Conditions

This permission does not apply to a work that:

1. uses interfaces Perysee marks as internal (`@internal`), undocumented hooks,
   or any other non-public mechanism;
2. modifies Perysee's files or behavior at runtime outside the Public Module API;
3. disables, bypasses, emulates or removes the license verification of any
   module, including official Perysee modules.

Such a work receives no permission from this exception. It is governed by
AGPLv3 alone.

## 4. Removal

Under section 7 of AGPLv3, you may remove this exception from your own modified
copies of Perysee. If you do, this exception no longer applies to those copies.

## 5. No warranty

This exception changes no part of the disclaimer of warranty and limitation of
liability in sections 15 and 16 of AGPLv3.
