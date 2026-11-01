# Example module

A small module that shows how a module extends Perysee. Read the files in this order:

1. `module.json`: identity, version, minimum Core version and dependencies.
2. `src/ModuleServiceProvider.php`: what the module adds (routes, shared data, and a list of other options).
3. `routes.php` and `src/GreetingController.php`: an action, with its role check.
4. `dist/module.js`: the browser side, a dashboard block and a settings page.

Enable it from Settings > Modules, or with `php artisan perysee:modules enable example`. It creates no table, so removing it leaves nothing behind: disable it, then delete the folder.
