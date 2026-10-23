# Configuration files

Flexi uses JSON configuration files to register shared component types such as services, routes, event listeners, commands, and queries. Their machine-readable structure and validation rules are maintained by the [`flexi/contracts` package](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.2.0/schemas/configuration/v1), which is the single source of truth for the core and modules.

The core must consume the schema version supported by its locked `flexi/contracts` dependency. Module-specific configuration files may define their own formats and schemas.

Service definitions may declare `decorates` to wrap another service ID and must use `@.inner` exactly once in class or factory arguments. Additional decorators form an explicit chain by targeting the previous decorator ID. Route definitions may declare `overrides` to replace a route while retaining its name. Duplicate service IDs, route names, `METHOD path` pairs, handler IDs, and command/query aliases are rejected during registration.
