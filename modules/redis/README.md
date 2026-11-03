# Redis

Perysee module `redis`.

- `module.json`: identity, version, minimum Core version, dependencies and entitlement.
- `src/ModuleServiceProvider.php`: what the module adds to Perysee (see the options below).
- `routes.php`: the module's routes, served under `/modules/redis`. Route names start with `redis.`.
- `dist/module.js`: the compiled UI. It reaches Vue and Inertia through `Perysee.Vue` and `Perysee.Inertia`.

## Extending Perysee

A module adds to what exists instead of getting its own corner:

| In the provider | Effect |
| --- | --- |
| `loadModuleRoutes($file)` | Pages and actions for signed-in users |
| `loadPublicRoutes($file)` | Routes without a session, under `/modules/redis/public` |
| `addNavigation($zone, $label, $icon, $route)` | An entry in the `workspace` or `admin` navigation |
| `shareData($closure)` | Data for your screens, as `modules.data["redis"]` |
| `brand($closure)` | Instance name, logo address and accent color |

| In `dist/module.js` | Effect |
| --- | --- |
| `Perysee.registerSettings("redis", component)` | The module's settings page, under Settings > Modules |
| `Perysee.registerSlot("site.report.after", component, { module: "redis" })` | A block under a site report (also `dashboard.after`) |
| `Perysee.registerScreen("module:redis", component)` | A full page |

Settings can be stored with `App\Modules\ModuleSettings` (`get`, `set`, `forget`, `all`).

Enable it with `php artisan perysee:modules enable redis`, or from Settings > Modules.
