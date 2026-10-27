# Cache Invalidation

Flexi keeps discovery and parsed configuration results only while the inputs that produced them remain unchanged.

## Module discovery

The module discovery cache is valid only while the contents of the root `composer.lock`, each discovered module's `composer.json`, and the package manifest set under the discovered local/vendor roots remain unchanged. Content hashes are used so a same-size edit or a timestamp-preserving deployment still invalidates the cached result. A new or removed module manifest changes the root snapshot. Invalid or legacy cache entries without these fingerprints are treated as cache misses and rebuilt.

Module detector results are held by detector instances. A new application build can therefore consult the shared discovery cache again, and `refreshModules()` invalidates it explicitly.

## Configuration files

Service, route, listener, and handler parser cache keys include both the normalized file path and its current content hash. A changed file is validated against its schema and parsed again even when the path was seen before. The same content at the same path can reuse its parsed result.

## Activation state and runtime registrations

Activation state is not stored in the discovery cache. `ConfigurationFilesProvider` filters modules using the current `ModuleStateManager` when configuration files are requested. A successful application/container build uses only the active modules' definitions; that container keeps its own definitions and service instances for its lifetime. An activation or deactivation affects a subsequent build, not objects already held by a running application process.

## Deployment guidance

Deploy Composer lockfile, module manifests, and configuration files as one release. Rebuild the application process after changing activation state or configuration. Flexi detects changed inputs automatically; no manual cache deletion is required for these changes.
