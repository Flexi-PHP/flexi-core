# Service Definitions and Resolution

Flexi reads service definitions from `services.json` files declared by the core or an active module. The JSON structure is defined by the [`flexi/contracts` services schema](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.2.0/schemas/configuration/v1). This guide describes the behavior implemented by the core container.

## Class definitions

Register a class under a stable service ID. Constructor arguments are positional. An argument beginning with `@` references another service ID; other JSON values are passed as configured. If `arguments` is omitted, the class is constructed without explicit arguments.

```json
{
  "services": [
    {
      "name": "App\\Mail\\MailerInterface",
      "class": {
        "name": "App\\Mail\\SmtpMailer",
        "arguments": ["@App\\Mail\\TransportInterface", "ENV.MAIL_FROM"]
      }
    }
  ]
}
```

The object builder resolves typed, non-builtin constructor parameters from the container by class or interface name first, then by parameter name. Explicit JSON arguments take precedence by being passed directly to the constructor. Builtin constructor parameters are not inferred; provide them explicitly. Optional constructor parameters use their PHP defaults when the object builder resolves dependencies automatically.

`ENV.NAME` and `env.NAME` read a required environment variable. Values that parse as booleans or numbers are converted to PHP scalar values; other values remain strings. Missing variables fail service creation.

## Aliases

An alias maps one ID to another ID. It can expose an implementation under an interface or application-facing name:

```json
{
  "services": [
    {"name": "App\\Mail\\SmtpMailer", "class": {"name": "App\\Mail\\SmtpMailer"}},
    {"name": "App\\Mail\\MailerInterface", "alias": "App\\Mail\\SmtpMailer"},
    {"name": "mailer", "alias": "App\\Mail\\MailerInterface"}
  ]
}
```

Aliases may point to another alias. Resolution ultimately requires a target service ID that is registered in the container. Avoid circular aliases; the current build does not pre-validate alias cycles. Retrieve services through the PSR-11 container using the ID; Flexi does not expose a separate `getByType()` operation.

## Factory definitions

A factory definition calls a static method and passes its positional arguments through the same service/environment reference resolver:

```json
{
  "services": [
    {
      "name": "App\\Http\\ClientInterface",
      "factory": {
        "class": "App\\Http\\ClientFactory",
        "method": "create",
        "arguments": ["@App\\Config\\HttpOptions"]
      }
    }
  ]
}
```

The method must be callable statically and return an object. The current configuration format does not provide a non-static factory service, a `shared` flag, or lazy/prototype scope controls.

## Resolution and instance lifetime

`ContainerInterface::get($id)` resolves the registered class, alias, or factory and caches its resulting object under that ID for the lifetime of that container/cache. Repeated lookups of the same ID therefore return the same object. Aliases resolve through their target ID, so the target's instance is reused. Different IDs with equivalent definitions are not guaranteed to share one instance.

Constructor autowiring uses registered service IDs that exactly match a non-builtin parameter type, including interface types. If that ID is unavailable, Flexi tries the parameter name. Register an alias when an interface should resolve to a concrete adapter.

## Replacing an adapter with decoration

Duplicate IDs are rejected, including when a module attempts to redefine a core service. A module can wrap an existing service with a distinct ID using the explicit `decorates` field and one `@.inner` argument:

```json
{
  "services": [
    {
      "name": "App\\Mail\\LoggingMailer",
      "decorates": "App\\Mail\\MailerInterface",
      "class": {
        "name": "App\\Mail\\LoggingMailer",
        "arguments": ["@.inner", "@Psr\\Log\\LoggerInterface"]
      }
    }
  ]
}
```

Decoration replaces the target's resolved definition for the built container. The decorator must accept the inner service as a constructor argument. Use a distinct decorator ID and target an existing definition; implicit replacement is not supported. Multiple decorators must form one explicit chain, and ambiguous chains or missing targets fail the build.

## Definition source diagnostics

The built container exposes `getServiceSources()`, returning a map from each configuration-defined service ID to the file that registered it. Startup collision and decorator errors include their source files. If resolving a configured service fails, the resulting `ServiceNotFoundException` includes its ID and source file; definitions registered directly with `Container::set()` have no configuration source.

## Module removal and rebuilds

The container is built from the core and active modules' service files. Deactivating or removing a module affects a newly built container; it does not mutate an existing container or remove objects already held by application code. Runtime cache invalidation and transactional rebuild behavior are tracked separately in the modular architecture roadmap.
