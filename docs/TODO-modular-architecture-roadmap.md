# TODO: solid modular foundation for Flexi applications

This document turns the integration guidelines for services, routes, listeners, and middlewares into verifiable work. The blocks are completed in order; a decision or implementation is only marked as finished when it meets its acceptance criteria.

## Initial state

- [x] Check the current Docker stack state without starting services.
- [x] Record the initial result: `docker-compose ps` showed no active containers on 2026-10-23.
- [x] Confirm with Podman that the `flexi` container is active and use it as the validation environment; the container exposes `8080 -> 80`.
- [x] Inventory the public module repositories and the versions/namespaces of `flexi-contracts` before changing contracts.
- [x] Record the available environment: PHP 7.4.33, Composer 2.8.12, and PHPUnit 9.6.33 inside the Podman container in [`runtime-requirements.md`](runtime-requirements.md).
- [x] Run the full PHPUnit suite after the initial changes: 655 tests, 1,860 assertions, all passing.
- [x] Perform an initial HTTP check: Apache responds in the container, but `GET /` returns 500 `Define at least one route`; the core route configuration is empty and the only local module is inactive.
- [x] Defer the HTTP smoke test requiring a built-in reference route; routes remain literal and built-in reference routes are intentionally outside the core for this iteration.
- [x] Inventory the declared PHP/extension requirements, locked transitive platform requirements, observed Composer/PHPUnit versions, and default external services in [`runtime-requirements.md`](runtime-requirements.md).

### Initial compatibility findings

| Surface | Observed state | Risk for contract changes |
|---|---|---|
| Local Flexi core | Development targets `flexi/contracts ^5.0`; `composer.lock` pins 5.0.0 at commit `c3a8eddf459120eaea61889cd36760965afbe2c9`. The checked-in `version` field remains at the latest released value until the core SemVer workflow calculates the next one from commit messages. | Use a breaking Conventional Commit for the core API migration and let the pipeline set the next core version. |
| Local `flexi-contracts` | Branch `main` is at tag `v5.0.0`, with PSR-4 namespace `Flexi\Contracts\`. | Contract API changes use a manually published GitHub release. |
| Published `flexi-contracts` | GitHub release `v5.0.0` is published for package `flexi/contracts` and namespace `Flexi\Contracts\`. | Packagist resolved the release for the core update. |
| Public modules | Auth, Cache, Logging, Session, 404, WebHooks, DevTools, HealthCheck, UI, and Home declare `cubadevops/flexi-contracts: @dev`; their PSR-4 prefixes remain `CubaDevOps\Flexi\Modules\...`. | The Composer requirement does not match the package required by the core (`flexi/contracts`). `@dev` also does not pin a reproducible version. |
| Published Cache configuration | Its `Config/services.json` references IDs `CubaDevOps\Flexi\Contracts\Interfaces\CacheInterface` and `...ConfigurationInterface`. | The IDs do not match the current namespace `Flexi\Contracts`; the registration may not bind to the interfaces loaded by the core. |

### Contract compatibility audit (2026-10-23)

The baseline lockfile resolved `flexi/contracts` 4.1.0 from commit `33069c8f00af231721879057ba2671f06a4cef6c`. Core and contracts now use the 5.0.0 line, with the core lockfile resolving commit `c3a8eddf459120eaea61889cd36760965afbe2c9`. There are no framework users or production applications requiring a compatibility line to be preserved, so core and contracts can make coordinated breaking corrections; existing modules will be migrated after this foundation is established.

Container validation used PHP 7.4.33 and PHPUnit 9.6.29. The first run against 4.1.0 failed when `RecordingListener` loaded because the old `EventListenerInterface` inherited the message-returning handler signature. After publishing contracts 5.0.0 and updating the core dependency through Composer, the full core suite passed: 655 tests and 1,860 assertions. The repository previously ignored all `*.lock` files; that rule is removed so the application lockfile can be tracked and keep dependency resolution reproducible.

| Consumer/API surface | Verified state | Required action |
|---|---|---|
| Core command and query handlers | All core `HandlerInterface` implementations and the test handlers declare `handle(DTOInterface): MessageInterface`, matching the locked 5.0 contract. | Preserve this signature for command/query handlers unless a major-version migration is explicitly planned. |
| Event listeners | Contracts 5.0.0 separates `EventListenerInterface` from `HandlerInterface` and requires `handle(EventInterface): void`. | Core suite passes with the published contract package. |
| `HandlerInterface` | Contracts 5.0.0 retains `handle(DTOInterface): MessageInterface` for command/query handlers. | Keep handler result semantics explicit and separate from event-listener semantics. |
| Criteria test double | `DummySearchCriteria` now implements the current `CriteriaInterface` methods and supplies empty filters, an `id` order, and no pagination values. | Keep fixtures aligned with the contract API as it evolves. |

**Versioning decision:** `flexi-contracts` follows SemVer but its release is manual: after committing and pushing the package changes/tag, publish the matching release from the GitHub interface so Packagist can expose it. Flexi Core's version is automated separately: its SemVer pipeline derives the next version from detected commit messages, so do not guess or manually bump the core `version` field. The core will depend on the matching contract major; existing module repositories will be migrated after the APIs stabilize.

### Development compatibility matrix

Use the canonical package and namespace as the sole target for the framework foundation. Existing repositories that require `cubadevops/flexi-contracts` or reference `CubaDevOps\Flexi\Contracts` are migration inputs, not a compatibility constraint. Adapt and verify those modules after contracts and core APIs stabilize.

| Line | Core | Contract package | Module requirements | Status |
|---|---|---|---|---|
| Active development | Flexi Core next release (SemVer pipeline) | `flexi/contracts` ^5.0 | New module work uses `flexi/contracts`, `Flexi\Contracts`, and the canonical `extra.flexi` manifest. | Contracts 5.2.0 is released and locked; core version is determined from commit messages. |
| Existing modules | Current published repositories | Legacy `cubadevops/flexi-contracts` requirement and/or `CubaDevOps\Flexi\Contracts` namespace. | Migrate Composer metadata, namespace references, manifests, and service registrations. | Migration follows core/contracts stabilization; compatibility is not a constraint in this phase. |

The `EventListenerInterface` separation is a breaking change because the interface is no longer a subtype of `HandlerInterface`; it shipped in contracts 5.0.0. Keep command/query handlers message-returning. No compatibility claim is made for existing modules until they are migrated and verified.

**Change rule:** use semantic major versions for breaking contract changes, but do not preserve unused legacy behavior at the expense of a coherent API. Adaptation of published modules follows core/contracts stabilization.

## Block 1 — Fix the module contract

- [x] Choose `extra.flexi` in Composer as the source of truth for the manifest and `config-files` as the explicit list of configurations.
- [x] Define the target compatibility matrix: canonical contracts/core are the active line; migrate existing modules after core/contracts stabilize.
- [x] Separate event listeners from command/query handlers in the contracts source so their return semantics do not conflict; release the API change only on the 5.x contract line.
- [x] Define the module manifest: logical name, Composer package version, Flexi/PHP compatibility, Composer dependencies, and provided capabilities in [`module-manifest.md`](module-manifest.md).
- [x] Use relative paths declared in `extra.flexi.config-files`; keep `Config/<type>.json` as a fallback for older modules without a list.
- [x] Omit a configuration type if an explicit list does not declare it.
- [x] Mark conflicting legacy module guides as historical references and link the canonical manifest specification.
- [x] Keep the extracted `example-auth` module out of the core repository; its remaining ignored local files are migration residue, not a core module to maintain.
- [x] Define the lifecycle explicitly: discovered, validated, dependencies resolved, active, registered, and inactive in [`module-lifecycle.md`](module-lifecycle.md).
- [x] Define deactivation's observable effect: inactive modules contribute no services, routes, listeners, commands, or queries after the next application build.
- [x] Require explicit activation: newly discovered modules initialize as inactive.

**Acceptance:** an independent module package can be installed, discovered, activated, and deactivated following a single guide without editing core configuration paths. The manifest schema and lifecycle are defined; runtime validation and publication steps are tracked below.

### Module package presence and local publication

- [x] Decide that Composer exclusively owns package installation, dependency resolution, and uninstallation; Flexi owns activation state and runtime registration.
- [x] Decide that publishing a vendor-installed module means copying its package into `modules/` and adding a Composer `path` repository entry for that local package.
- [x] Treat publication as a one-way development transition. Returning to a Packagist package requires manually removing the local path repository entry, then installing/updating the published Composer package normally.
- [x] Implement `modules:publish` with manifest validation, safe copy, a Composer path repository pinned to the locked version, targeted Composer update, rollback/recovery, and an explicit report when recovery fails.
- [x] Ensure local and vendor discovery identify the same module by canonical `extra.flexi.module-name`; treat a Composer path symlink and its local source as one module and reject distinct ambiguous duplicate sources.
- [x] Reconcile persisted activation state with discovered Composer packages so newly discovered packages are inactive and removed packages cannot leave stale activation state.

**Acceptance:** Composer is the only source of package presence; a published local copy is editable under `modules/` and Composer resolves it through a path repository; activation remains a separate explicit choice.

## Block 2 — Configuration validation and composition

- [x] Define machine-readable schemas for `services`, `routes`, `listeners`, `commands`, and `queries` in [`flexi/contracts`](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.2.0/schemas/configuration/v1), the single source of truth for the core and modules.
- [x] Validate all active module files against the Contracts schemas before registering components; report module, file, JSON path, and validation cause.
- [x] Detect duplicate service IDs, route names and method/path pairs, and handler IDs/aliases; fail explicitly instead of silently overwriting.
- [x] Define a deliberate policy for service replacement/decoration and route overrides; require explicit declarations and reject ambiguous decoration chains.
- [x] Define and validate circular or incompatible dependencies between active modules before building the application. The core checks declared core compatibility, required module activation/version constraints, and dependency cycles; Composer remains responsible for resolving external packages.
- [x] Agree on a deterministic load order and priority resolution, and document it in [`configuration-load-order.md`](configuration-load-order.md). Core configuration loads first; the root Composer manifest may override the alphabetical order of active modules. Unlisted active modules remain alphabetical, definitions preserve file order, and conflicts require explicit decorator/route override rules. Listeners currently have equal priority and stable registration order.

**Acceptance:** invalid or ambiguous configuration fails at startup with actionable diagnostics; valid configuration produces the same registration log on each execution.

## Block 3 — Service lifecycle

- [x] Document class, alias, and factory definitions with minimal examples and supported argument types in [`service-definitions.md`](service-definitions.md).
- [x] Define resolution by ID and by interface, including the relationship between aliases, shared instances, and factories in [`service-definitions.md`](service-definitions.md).
- [x] Allow modules to replace core adapters through the explicit, validated service decorator mechanism; see [`service-definitions.md`](service-definitions.md) and [`configuration-load-order.md`](configuration-load-order.md).
- [x] Ensure removing or deactivating a module removes its definitions from the rebuilt container; service definitions and instances are now scoped to each container build, with a regression test for a module disappearing between builds.
- [x] Define cache invalidation for configuration by Composer/module manifest content, module directory membership, file content, and activation state in [`cache-invalidation.md`](cache-invalidation.md).
- [x] Add diagnostics that identify the source of each loaded definition and help explain conflicts; the container exposes service ID-to-file mappings and includes origins in configured-service resolution failures.

**Acceptance:** the developer can add, replace, or remove an implementation using documented rules, and the changes are not hidden by stale cache.

## Block 4 — Routes and HTTP pipeline

- [x] Document the current Contracts route schema: methods, literal paths, query/form parameters, names, controllers, middleware IDs, and explicit overrides in [`http-routing.md`](http-routing.md).
- [x] Adopt literal route paths as the stable routing policy: route identity and collisions use the literal path plus HTTP method; request values use query/form parameters.
- [x] Resolve route and method conflicts at startup, with the originating configuration file included in the error.
- [x] Defer route groups and prefix composition; keep route matching literal until a concrete use case establishes precedence requirements.
- [x] Make the pipeline use PSR-7/PSR-15 consistently and ensure middleware application does not depend on the controller extending a concrete class.
- [x] Resolve middleware via IDs/definitions from the container while respecting aliases, factories, and dependency injection.
- [x] Define middleware precedence, short-circuiting, and error propagation; test order and response when one stops the chain in [`http-routing.md`](http-routing.md).
- [x] Specify and implement HTTP behavior: empty/unknown route returns 404 (with `RouteNotFoundEvent`); method mismatch returns 405 with `Allow`; missing required query/form parameters return 400; unexpected web exceptions return a generic 500 in production and include the message only in debug mode.
- [x] Defer built-in reference routes; health checks and examples belong to an optional module or application until their ownership and response contract are needed.

**Acceptance:** routes from an active module work with the registered middlewares; when disabled, they disappear; invalid configuration and collisions are detected before serving requests.

## Block 5 — Listeners, commands, and queries

- [x] Define registration contracts for listeners, commands, and queries, including the ID and handler class in [`message-handlers.md`](message-handlers.md) and the Contracts schemas.
- [x] Establish listener ordering and tie-break rules: equal priority, then stable configuration/definition/listener registration order; specific event listeners precede wildcard listeners.
- [x] Define handler cardinality: one command/query handler per ID, with unique aliases; multiple event listeners per event are allowed. Collisions fail with source files.
- [x] Verify known listener/handler classes and interface compatibility during container build without constructing handlers/listeners; keep runtime instantiation lazy to avoid constructor side effects during validation.
- [x] Define error propagation and listener stop behavior: handler/listener exceptions propagate, and stopped events skip later listeners; see [`message-handlers.md`](message-handlers.md).
- [x] Ensure that activating or deactivating a module adds or removes all its registrations in a clean rebuild; bus builds use the current configuration file set, covered by a regression test.
- [x] Preflight a candidate module and its combined configuration with currently active modules before persisting activation.
- [x] Dispatch typed module-activated and module-deactivated events after successful state changes so listeners can invalidate module/configuration caches or rebuild derived manifests.

**Acceptance:** priorities and order are observable and deterministic; handler errors are detected with their originating module; inactive modules leave no lingering listeners or handlers behind.

## Block 6 — Security, operations, and performance

- [x] Review required PHP extensions; make `ext-pcntl` optional and document that it is required only for explicitly enabled CLI async dispatch in [`runtime-requirements.md`](runtime-requirements.md).
- [x] Document the trust model: a Composer package executes PHP code and functional deactivation is not security isolation in [`security-and-environment.md`](security-and-environment.md).
- [x] Separate configuration by environment and secrets; label example values as development-only and document process environment secret injection in [`security-and-environment.md`](security-and-environment.md).
- [x] Define minimum runtime ownership/access guidance for caches, module state, and logs in [`security-and-environment.md`](security-and-environment.md); application-specific size/retention quotas remain deployment settings until adapter/workload policy is chosen.
- [x] Keep validation and content-based configuration/module caches enabled in every environment; rebuild and restart processes after deployment changes instead of adding a separate production-mode switch.
- [x] Record a reproducible bootstrap and HTTP dispatch baseline for a minimal app and one active module in [`performance-baseline.md`](performance-baseline.md); optimize only when repeated measurements show a material regression or a concrete workload needs it.
- [x] Restrict asynchronous dispatch to explicitly enabled CLI use and check process capabilities only when requested; document this optional requirement in [`runtime-requirements.md`](runtime-requirements.md).

**Acceptance:** Flexi starts in the documented minimum environment, fails safely on invalid configuration, and has reproducible measurements to justify performance decisions.

## Block 7 — Verification and developer guidance

- [x] Cover schemas for services, routes, listeners, commands, and queries and activation/deactivation registration rebuilds in `ConfigurationValidatorTest`, parser/factory tests, and module activation tests.
- [x] Test HTTP routing and middleware behavior in the container, including status codes, the 405 `Allow` header, and an empty-route 404 response.
- [x] Test duplicate service IDs, handler IDs/aliases, and route names across multiple configuration files at their factory boundaries in `ContainerFactoryTest`, `BusFactoryTest`, and `RouterFactoryTest`.
- [x] Exercise resolution and collisions across independently installed Composer-style package fixtures, including activation, services, route/middleware dispatch, commands, listeners, and route/handler conflicts.
- [x] Test cache hit/miss behavior and Composer lock, module manifest, module membership, and configuration content changes in `ModuleCacheManagerTest`, parser tests, and cache invalidation tests.
- [x] Add a minimal module template with a route, service, middleware, and optional listener in [`module-template.md`](module-template.md).
- [x] Document atomic recipes for install/activate, add a capability, replace an adapter, deactivate, and remove in [`module-recipes.md`](module-recipes.md).
- [x] Align the main README's installation, middleware, and module-guide examples with current behavior.
- [x] Inventory historical architecture documents, mark superseded proposals/reports, and add a central index linking current references in [`historical-documentation.md`](historical-documentation.md). Historical example code remains preserved as archival material.
- [x] Define CI for Composer metadata validation, PHP syntax lint, unit/integration tests, and compatibility with the declared PHP `>=7.4` floor; run checks on pushes and pull requests to `main` and `develop`.

**Acceptance:** a new developer can follow the template to add and remove a capability without editing core internals; CI reproduces the documented checks.

## Deferred decisions and verification

These implementation details remain deferred because they need more API design or an explicit deployment policy:

- Route API decision: keep paths literal and identify a route by its literal path and HTTP method. Pass variable input through query/form parameters. This avoids pattern matching and hidden ambiguity in the router. Group/prefix composition, arbitrary route metadata, and built-in reference routes remain deferred.
- Handler preflight: known classes are checked during container build without constructing instances. Untyped factory outputs and constructor dependency resolution remain runtime checks.
- Production mode and performance: no separate production-mode switch will be added. Caches remain content-based, and deployment must rebuild/restart application processes after configuration or module changes. The current local baseline is recorded in [`performance-baseline.md`](performance-baseline.md); optimize only after repeated measurements or a concrete workload justify it.
- End-to-end module verification: the integration test exercises Composer-style vendor package fixtures through discovery, activation, container/router/bus construction, HTTP dispatch, and collision detection. It creates package metadata and files directly instead of invoking Composer to install external module repositories; deactivation/removal remain covered by module lifecycle and cache rebuild tests.
- [x] Debug CLI smoke test: `modules:list` completes with the development default `debug=true`; the error-handler wrapper respects PHP's `error_reporting()` mask so suppressed deprecations do not become exceptions.
- Historical docs: superseded architecture proposals and reports are indexed and labeled. Their historical content and translation scope remain unchanged.

## Module management decisions

- Composer package presence defines installation: `composer require` installs and `composer remove` uninstalls. Flexi does not duplicate these package-manager operations.
- `modules:publish` copies an installed vendor package into `modules/`, registers that directory as a Composer `path` repository, and updates the Composer installation so the local copy is the active package source. Publication is intentionally one-way.
- To return to a published package, the developer manually removes the local `path` repository entry and then runs the conventional Composer installation/update for that package.
- Activation is a separate persisted choice. A module must pass preflight before activation state changes. Activation and deactivation emit lifecycle events after successful state changes.

## Progress rule

Complete each block in order, review the diff and acceptance criteria before marking it as done. Do not start implementation changes until the Block 1 contract has been agreed; do not use performance as an abstract goal without a measured baseline.
