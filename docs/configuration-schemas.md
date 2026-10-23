# Configuration files

Flexi uses JSON configuration files to register shared component types such as services, routes, event listeners, commands, and queries. Their machine-readable structure and validation rules are maintained by the [`flexi/contracts` package](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.1.0/schemas/configuration/v1), which is the single source of truth for the core and modules.

The core must consume the schema version supported by its locked `flexi/contracts` dependency. Module-specific configuration files may define their own formats and schemas.
