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
- [ ] Repeat the HTTP smoke test once a reference route exists and is active.
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
- [ ] Align `modules/example-auth` with manifest schema v1 after core/contracts stabilization; module files are excluded from this repository by `.gitignore` and module migration is deferred by scope.
- [x] Define the lifecycle explicitly: discovered, validated, dependencies resolved, active, registered, and inactive in [`module-lifecycle.md`](module-lifecycle.md).
- [x] Define deactivation's observable effect: inactive modules contribute no services, routes, listeners, commands, or queries after the next application build.
- [x] Require explicit activation: newly discovered modules initialize as inactive.

**Acceptance:** a valid example module can be installed, discovered, activated, and deactivated following a single guide without editing core configuration paths. The manifest schema and lifecycle are defined; applying them to the existing example is deferred until module migration, and runtime validation remains in Block 2.

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
- [ ] Decide whether to add parameterized path matching or arbitrary route metadata; defer schema changes until this API decision is reviewed.
- [x] Resolve route and method conflicts at startup, with the originating configuration file included in the error.
- [ ] Define route composition by application, module, group, and individual route, including prefixes and ordering.
- [x] Make the pipeline use PSR-7/PSR-15 consistently and ensure middleware application does not depend on the controller extending a concrete class.
- [x] Resolve middleware via IDs/definitions from the container while respecting aliases, factories, and dependency injection.
- [x] Define middleware precedence, short-circuiting, and error propagation; test order and response when one stops the chain in [`http-routing.md`](http-routing.md).
- [ ] Specify the expected HTTP behavior for missing routes, disallowed methods, invalid parameters, and exceptions.
- [ ] Create reference routes (health check and example route) that demonstrate the contract without coupling business logic to the core.

**Acceptance:** routes from an active module work with the registered middlewares; when disabled, they disappear; invalid configuration and collisions are detected before serving requests.

## Block 5 — Listeners, commands, and queries

- [x] Define registration contracts for listeners, commands, and queries, including the ID and handler class in [`message-handlers.md`](message-handlers.md) and the Contracts schemas.
- [x] Establish listener ordering and tie-break rules: equal priority, then stable configuration/definition/listener registration order; specific event listeners precede wildcard listeners.
- [x] Define handler cardinality: one command/query handler per ID, with unique aliases; multiple event listeners per event are allowed. Collisions fail with source files.
- [ ] Verify that each listener/handler is instantiable by the container and compatible with its contract before accepting traffic/work. Eager construction is deferred pending agreement about constructor side effects.
- [x] Define error propagation and listener stop behavior: handler/listener exceptions propagate, and stopped events skip later listeners; see [`message-handlers.md`](message-handlers.md).
- [x] Ensure that activating or deactivating a module adds or removes all its registrations in a clean rebuild; bus builds use the current configuration file set, covered by a regression test.

**Acceptance:** priorities and order are observable and deterministic; handler errors are detected with their originating module; inactive modules leave no lingering listeners or handlers behind.

## Block 6 — Security, operations, and performance

- [x] Review required PHP extensions; make `ext-pcntl` optional and document that it is required only for explicitly enabled CLI async dispatch in [`runtime-requirements.md`](runtime-requirements.md).
- [x] Document the trust model: a Composer package executes PHP code and functional deactivation is not security isolation in [`security-and-environment.md`](security-and-environment.md).
- [x] Separate configuration by environment and secrets; label example values as development-only and document process environment secret injection in [`security-and-environment.md`](security-and-environment.md).
- [x] Define minimum runtime ownership/access guidance for caches, module state, and logs in [`security-and-environment.md`](security-and-environment.md); application-specific size/retention quotas remain deployment settings until adapter/workload policy is chosen.
- [ ] Establish a production mode with validated and cached configuration plus explicit invalidation/rebuild during deployment.
- [ ] Measure bootstrap and HTTP dispatch times with a minimal app and with modules; optimize only with reproducible measurements.
- [x] Restrict asynchronous dispatch to explicitly enabled CLI use and check process capabilities only when requested; document this optional requirement in [`runtime-requirements.md`](runtime-requirements.md).

**Acceptance:** Flexi starts in the documented minimum environment, fails safely on invalid configuration, and has reproducible measurements to justify performance decisions.

## Block 7 — Verification and developer guidance

- [x] Cover schemas for services, routes, listeners, commands, and queries and activation/deactivation registration rebuilds in `ConfigurationValidatorTest`, parser/factory tests, and module activation tests.
- [ ] Test HTTP routes and middlewares with the Docker stack, verifying status codes, headers, order, and errors.
- [ ] Test resolution and collisions of services, listeners, commands, and queries with independent modules.
- [x] Test cache hit/miss behavior and Composer lock, module manifest, module membership, and configuration content changes in `ModuleCacheManagerTest`, parser tests, and cache invalidation tests.
- [ ] Add a minimal module template with a route, service, middleware, and optional listener.
- [ ] Document atomic recipes: install/activate, add a capability, replace an adapter, deactivate, and remove.
- [ ] Align README, historical documentation, and examples with the implemented behavior; mark old proposals as deprecated.
- [ ] Define CI for static validation, unit tests, integration tests, and compatibility with supported versions.

**Acceptance:** a new developer can follow the template to add and remove a capability without editing core internals; CI reproduces the documented checks.

## Progress rule

Complete each block in order, review the diff and acceptance criteria before marking it as done. Do not start implementation changes until the Block 1 contract has been agreed; do not use performance as an abstract goal without a measured baseline.
