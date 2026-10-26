# Module Recipes

These recipes use the canonical `extra.flexi` manifest and the registered CLI aliases. They apply to trusted Composer packages; an inactive package remains executable PHP code and is not isolated from the application.

## Install and activate a module

```bash
composer require acme/flexi-module-example
php bin/console --command modules:validate
php bin/console --command modules:list
php bin/console --command modules:activate module_name=Example
```

Confirm the logical module name shown by `modules:list`. Activation updates persisted module state and may merge environment defaults from the package into the root `.env`; review the diff before using those values. Restart application workers or rebuild the application so their registrations reflect the new active state.

## Publish a vendor module for local editing

Use Flexi's publish command to copy an installed module from `vendor/` into `modules/` and add a Composer `path` repository entry pointing to that copy:

```bash
php bin/console --command modules:publish module_name=Example
```

The command updates the Composer installation so the local package becomes the source Composer autoloads. Review the `composer.json`, `composer.lock`, and copied package before committing. Publication is intentionally one-way. To use an officially published package again, manually remove its local `path` repository entry from the root `composer.json`, then run the normal Composer update/install for that package. Flexi does not provide an unpublish command.

Publishing does not activate the module. Validate and explicitly activate it as a separate operation.

## Add a capability

1. Add the PSR-4 class and its configuration file to the module package.
2. Add the relative configuration path to `extra.flexi.config-files`.
3. Add or update the capability identifier in `extra.flexi.provides` for discovery/documentation.
4. Validate the package configuration and run the application tests before publishing a package release.

`provides` currently documents a capability; it does not activate a module or replace a Composer dependency. Use `require` for required module packages.

## Replace a service adapter

Add a service definition that explicitly decorates the existing service ID:

```json
{
  "services": [
    {
      "name": "app.logged_mailer",
      "decorates": "mailer",
      "class": {
        "name": "Acme\\Flexi\\Logging\\LoggedMailer",
        "arguments": ["@.inner"]
      }
    }
  ]
}
```

The decorator must use `@.inner` exactly once. Do not redeclare the target ID directly; duplicate service IDs fail during container construction. Decorator chains must be explicit and unambiguous.

## Deactivate or remove a module

```bash
php bin/console --command modules:deactivate module_name=Example
composer remove acme/flexi-module-example
```

Deactivation removes the module's registrations on the next build; it does not uninstall its code. Removing the Composer package removes the dependency and its autoloader. Restart long-running application processes after either operation. Review `.env` changes after deactivation because the command may remove the module's managed environment block.

## Route override

Route replacement is supported only when the replacement keeps the target route name and declares the same value in `overrides`:

```json
{
  "routes": [
    {
      "name": "account.profile",
      "overrides": "account.profile",
      "path": "/account/profile",
      "controller": "Acme\\Flexi\\Account\\ProfileHandler",
      "method": "GET"
    }
  ]
}
```

Only one override may target a route. Paths are intentionally literal; callers pass variable values as query/form parameters. Route groups and prefixes remain deferred; see [HTTP routing](http-routing.md#deferred-route-composition).
