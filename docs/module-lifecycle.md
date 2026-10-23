# Flexi Module Lifecycle

This document defines the lifecycle of an installed Flexi module. The lifecycle separates package presence, validation, operator intent, and registrations in a particular application build.

## Lifecycle states

| State | Meaning |
|---|---|
| **Discovered** | The module package or local module directory is present and its package identity can be read. Discovery does not execute or register the module's components. |
| **Validated** | The Composer metadata, Flexi manifest, and declared configuration files pass the applicable structural checks. Invalid modules must produce diagnostics and must not contribute runtime registrations. |
| **Dependencies resolved** | Composer has installed the required packages, and Flexi has verified any module-level requirements that cannot be expressed through Composer alone. |
| **Inactive** | The module is installed and available but the operator has not enabled it. None of its services, routes, listeners, commands, or queries are registered. |
| **Active** | The operator has persisted the intent to enable the module. This is desired state; it is distinct from whether this process has built its runtime registrations yet. |
| **Registered** | During a successful application build, the active module's validated components have been added to that build's service container and other registries. This state is runtime-specific and is not persisted as installation state. |

## Transitions and failures

1. Package installation makes a module eligible for discovery. Composer resolves package dependencies before application bootstrap.
2. Discovery reads package metadata and identifies the module. A package without valid Flexi metadata is not treated as a canonical module.
3. Validation checks the manifest and declared files before any component is registered.
4. A discovered, valid module starts **inactive** unless an explicit persisted activation says otherwise.
5. Activation changes the persisted desired state to **active**. Deactivation changes it to **inactive**.
6. On the next application build, the core validates active candidates, checks module requirements, and registers their declared components. A successful build makes those components **registered** for that process.

Validation, dependency, or registration errors must identify the package/module and the failing file or requirement. A failed build must not expose a partially populated runtime as ready to serve traffic. Recovery requires correcting the cause and rebuilding the application.

Activation or deactivation does not mutate an already-built container or router in place. It takes effect on the next application rebuild or process restart. This keeps the runtime registration set deterministic for the lifetime of a process.

## Deactivation and removal

After a rebuild with a module inactive, that module contributes no service definitions, routes, listeners, commands, or queries. Definitions from other modules and the core remain available. Removing the package also removes it from discovery on the next build.

Disabling a module is functional deactivation, not a security boundary: Composer-installed PHP code remains present and can still be loaded by application code. Do not install untrusted packages.

## Current implementation status

The core persists activation state, initializes newly discovered modules as inactive, and the configuration provider filters configuration files to active modules. Unit coverage verifies the inactive-by-default behavior; the full suite passes in the Podman container on PHP 7.4.33 with 656 tests and 1,864 assertions. The full lifecycle guarantees above are the target contract; validation before all registration, dependency checks, atomic runtime builds, and complete removal of all component registrations still require implementation and integration coverage. Activation changes require a rebuilt application process to affect its already-constructed registries.
