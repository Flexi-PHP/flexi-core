# Security and Environment Configuration

## Module trust boundary

A Composer module is executable PHP code. Installing a package allows its code to run with the application's process permissions. Deactivating a module only removes its declared registrations from the next application build; it does not unload its PHP files or provide a security sandbox. Install modules only from sources you trust and review their code and dependency changes.

## Environment values and secrets

The repository's `.env.example` and `.env.testing.example` contain development/test values. `.env` and `.env.testing` are local runtime files and are ignored by Git. Never place production credentials in the examples or commit local secret files.

The configuration repository loads the root `.env` as a fallback for values not already present in the process environment. In production, inject secrets through the deployment platform's environment/secret facility, set `debug=false`, and supply a generated `webhook_secret` (and any module-specific secrets). The example `test-web-secret` is not suitable for a deployed application.

Service argument references such as `ENV.MAIL_FROM` read environment values at resolution time. Missing referenced values fail service creation rather than silently using an empty value.

## Runtime files and permissions

The application process must be able to write the configured logs and the `var` paths used for cache and persisted module state. Assign ownership to the runtime user and grant only the access that user and required deployment group need. Do not make the project tree or these directories world-writable. State and discovery cache writes use temporary files and rename where supported; application logs are managed by the configured logger.

Flexi does not currently impose a maximum size or retention quota for application logs, module state, or cache data. Configure log rotation and storage limits in the deployment environment. A framework-level quota needs a separate policy because safe limits depend on the selected cache/logger adapters and application workload.
