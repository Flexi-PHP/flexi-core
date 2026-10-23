# TODO: solid modular foundation for Flexi applications

This document turns the integration guidelines for services, routes, listeners, and middlewares into verifiable work. The blocks are completed in order; a decision or implementation is only marked as finished when it meets its acceptance criteria.

## Initial state

- [x] Check the current Docker stack state without starting services.
- [x] Record the initial result: `docker-compose ps` showed no active containers on 2026-10-23.
- [x] Confirm with Podman that the `flexi` container is active and use it as the validation environment; the container exposes `8080 -> 80`.
- [x] Inventory the public module repositories and the versions/namespaces of `flexi-contracts` before changing contracts.
- [x] Record the host limitation (no PHP) and the available environment: PHP 7.4.33 + PHPUnit 9.6.29 inside the Podman container.
- [x] Run the full PHPUnit suite after the initial changes: 655 tests, 1,860 assertions, all passing.
- [x] Perform an initial HTTP check: Apache responds in the container, but `GET /` returns 500 `Define at least one route`; the core route configuration is empty and the only local module is inactive.
- [ ] Repeat the HTTP smoke test once a reference route exists and is active.
- [ ] Inventory the required PHP, Composer, extension, and service versions needed to run the project and its tests.

### Initial compatibility findings

| Surface | Observed state | Risk for contract changes |
|---|---|---|
| Local Flexi core | Requires `flexi/contracts >=4.1.0`; `composer.lock` pins 4.1.0. | The version range allows future versions without an upper bound; compatibility semantics must be defined and the resolved version must be tested. |
| Local `flexi-contracts` | Branch `main` is at tag `v4.1.0`, commit `33069c8`, with PSR-4 namespace `Flexi\Contracts\`. | The local checkout matches the package revision currently locked by the core. |
| Published `flexi-contracts` | The checked default branch and tag `v4.1.0` declare package `flexi/contracts` and namespace `Flexi\Contracts\`. | Keep the package version and lockfile reference aligned when changing the contract API. |
| Public modules | Auth, Cache, Logging, Session, 404, WebHooks, DevTools, HealthCheck, UI, and Home declare `cubadevops/flexi-contracts: @dev`; their PSR-4 prefixes remain `CubaDevOps\Flexi\Modules\...`. | The Composer requirement does not match the package required by the core (`flexi/contracts`). `@dev` also does not pin a reproducible version. |
| Published Cache configuration | Its `Config/services.json` references IDs `CubaDevOps\Flexi\Contracts\Interfaces\CacheInterface` and `...ConfigurationInterface`. | The IDs do not match the current namespace `Flexi\Contracts`; the registration may not bind to the interfaces loaded by the core. |

### Contract compatibility audit (2026-10-23)

The core resolves `flexi/contracts` 4.1.0 from commit `33069c8f00af231721879057ba2671f06a4cef6c`. After the latest pull, the local `flexi-contracts` checkout is also at `v4.1.0`/`33069c8`; comparing the source trees shows they match (apart from a local `.DS_Store` file). The earlier observation that the local checkout was at 5.0.1 is obsolete and has been removed.

| Consumer/API surface | Verified state | Required action |
|---|---|---|
| Core command and query handlers | All core `HandlerInterface` implementations and the test handlers declare `handle(DTOInterface): MessageInterface`, matching the locked 4.1 contract. | Preserve this signature for command/query handlers unless a major-version migration is explicitly planned. |
| Event listeners | `EventListenerInterface` extends `HandlerInterface`; the published `EventListener` base class implements `handle(DTOInterface): void`. The locked 4.1 `HandlerInterface` requires `MessageInterface`, making the base class declaration incompatible when loaded. | Separate event-listener semantics from request/command handler semantics or otherwise resolve the return-type contract. Audit public modules and provide a migration path before release. |
| `HandlerInterface` change in 4.1 | Commit `33c49b4` changed `handle(DTOInterface)` to require `MessageInterface`; this change is included in the locked 4.1.0 source. | Preserve the typed result for command/query handlers and assess the listener compatibility issue below before the next contract release. |
| Criteria test double | `DummySearchCriteria` implements `CriteriaInterface` with obsolete methods; the current interface requires `filters`, `order`, `limit`, `offset`, and `pointer`. | Update or remove the stale fixture when criteria behavior is next covered by tests. |

**Compatibility decision still needed:** define separate compatibility lines for the legacy `cubadevops/flexi-contracts` dependency and the canonical `flexi/contracts` package, identify the first core/module versions that support each line, and state whether a package/API change is patch, minor, or major. Do not loosen or tighten the core constraint until that matrix is agreed.

**Compatibility rule:** changes to the central package must preserve existing contracts or introduce an explicit migration/versioning path. Adaptation of the published modules will be done later as a coordinated phase; their code and repositories are not changed here.

## Block 1 — Fix the module contract

- [x] Choose `extra.flexi` in Composer as the source of truth for the manifest and `config-files` as the explicit list of configurations.
- [ ] Define the compatibility matrix between core versions, `flexi/contracts`, and modules, including the transition from legacy package and namespace names; resolve the `HandlerInterface`/`EventListener` return-type conflict.
- [ ] Define the module manifest: name, version, Flexi/PHP compatibility, dependencies, and provided capabilities.
- [x] Use relative paths declared in `extra.flexi.config-files`; keep `Config/<type>.json` as a fallback for older modules without a list.
- [x] Omit a configuration type if an explicit list does not declare it.
- [ ] Align `modules/example-auth` and legacy documentation with the agreed contract.
- [ ] Define the lifecycle explicitly: discovered, validated, dependencies resolved, active, registered, and inactive.
- [ ] Define the observable effect of disabling a module: its services, routes, listeners, commands, and queries stop being registered.

**Acceptance:** a valid example module can be installed, discovered, activated, and deactivated following a single guide without editing core configuration paths.

## Block 2 — Configuration validation and composition

- [ ] Define schemas for `services`, `routes`, `listeners`, `commands`, and `queries`, including types, required fields, and allowed values.
- [ ] Validate all active module files before registering components; report file, module, key, and error cause.
- [ ] Detect duplicate service IDs, names/routes, and handler registrations; fail explicitly instead of ignoring or silently overwriting.
- [ ] Define a deliberate policy for service replacement/decoration; do not let load order accidentally decide the implementation.
- [ ] Define and validate circular or incompatible dependencies between modules before building the application.
- [ ] Agree on a deterministic load order and priority resolution, and document it.

**Acceptance:** invalid or ambiguous configuration fails at startup with actionable diagnostics; valid configuration produces the same registration log on each execution.

## Block 3 — Service lifecycle

- [ ] Document class, alias, and factory definitions with minimal examples and supported argument types.
- [ ] Define resolution by ID and by interface, including the relationship between aliases, shared instances, and factories.
- [ ] Allow modules to replace core adapters through an explicit and validated mechanism.
- [ ] Ensure removing or deactivating a module removes its definitions from the rebuilt container.
- [ ] Define cache invalidation for configuration by manifest content/version, file changes, and module state.
- [ ] Add diagnostics that identify the source of each loaded definition and help explain conflicts.

**Acceptance:** the developer can add, replace, or remove an implementation using documented rules, and the changes are not hidden by stale cache.

## Block 4 — Routes and HTTP pipeline

- [ ] Define the route schema: methods, patterns and parameters, name, controller, middleware, and metadata.
- [ ] Resolve route and method conflicts at startup, with the originating module included in the error.
- [ ] Define route composition by application, module, group, and individual route, including prefixes and ordering.
- [ ] Make the pipeline use PSR-7/PSR-15 consistently and ensure middleware application does not depend on the controller extending a concrete class.
- [ ] Resolve middleware via IDs/definitions from the container while respecting aliases, factories, and dependency injection.
- [ ] Define middleware precedence, short-circuiting, and error handling; test order and response when one stops the chain.
- [ ] Specify the expected HTTP behavior for missing routes, disallowed methods, invalid parameters, and exceptions.
- [ ] Create reference routes (health check and example route) that demonstrate the contract without coupling business logic to the core.

**Acceptance:** routes from an active module work with the registered middlewares; when disabled, they disappear; invalid configuration and collisions are detected before serving requests.

## Block 5 — Listeners, commands, and queries

- [ ] Define registration contracts for listeners, commands, and queries, including the ID and handler class.
- [ ] Enforce listener priority and establish stable tie-break rules.
- [ ] Define whether handlers are unique by ID or whether multiple are allowed; validate that policy.
- [ ] Verify that each listener/handler is instantiable by the container and compatible with its contract before accepting traffic/work.
- [ ] Define how errors are reported and how propagation is stopped in listeners; do not hide exceptions without an explicit policy.
- [ ] Ensure that activating or deactivating a module adds or removes all its registrations in a clean rebuild.

**Acceptance:** priorities and order are observable and deterministic; handler errors are detected with their originating module; inactive modules leave no lingering listeners or handlers behind.

## Block 6 — Security, operations, and performance

- [ ] Review required PHP extensions and dependencies; move non-universal capabilities to optional adapters when appropriate.
- [ ] Document the trust model: a Composer package executes PHP code and functional deactivation is not security isolation.
- [ ] Separate configuration by environment and secrets; avoid unsafe example values that could be confused with production secrets.
- [ ] Define permissions and write limits for caches, module state, and logs.
- [ ] Establish a production mode with validated and cached configuration plus explicit invalidation/rebuild during deployment.
- [ ] Measure bootstrap and HTTP dispatch times with a minimal app and with modules; optimize only with reproducible measurements.
- [ ] Verify that async mode and process usage are only available in compatible environments and are not accidental requirements for a normal web app.

**Acceptance:** Flexi starts in the documented minimum environment, fails safely on invalid configuration, and has reproducible measurements to justify performance decisions.

## Block 7 — Verification and developer guidance

- [ ] Create integration tests for each configuration file and for activation/deactivation behavior.
- [ ] Test HTTP routes and middlewares with the Docker stack, verifying status codes, headers, order, and errors.
- [ ] Test resolution and collisions of services, listeners, commands, and queries with independent modules.
- [ ] Test cold/warm cache behavior and manifest/configuration changes to avoid stale results.
- [ ] Add a minimal module template with a route, service, middleware, and optional listener.
- [ ] Document atomic recipes: install/activate, add a capability, replace an adapter, deactivate, and remove.
- [ ] Align README, historical documentation, and examples with the implemented behavior; mark old proposals as deprecated.
- [ ] Define CI for static validation, unit tests, integration tests, and compatibility with supported versions.

**Acceptance:** a new developer can follow the template to add and remove a capability without editing core internals; CI reproduces the documented checks.

## Progress rule

Complete each block in order, review the diff and acceptance criteria before marking it as done. Do not start implementation changes until the Block 1 contract has been agreed; do not use performance as an abstract goal without a measured baseline.
