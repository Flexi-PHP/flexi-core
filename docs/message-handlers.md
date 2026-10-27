# Event Listeners, Commands, and Queries

Flexi loads listeners, commands, and queries from the configuration files defined by the [`flexi/contracts` schemas](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.2.0/schemas/configuration/v1). Their entries refer to handler class names. Handler services may also be registered in `services.json` so they can be resolved through a service ID, alias, or factory.

## Event listeners

One event may have multiple listeners. They run in registration order: configuration-file order, then event definition order, then listener-array order. The event-specific listener list runs before listeners registered for `*`. The schema has no priority field, so configured listeners currently have equal priority.

Listeners implement `Flexi\\Contracts\\Interfaces\\EventListenerInterface` and receive an `EventInterface`. If a listener calls `stopPropagation()`, later listeners are skipped. Listener exceptions are not swallowed; they propagate out of event dispatch and stop the remaining synchronous listeners.

```json
[
  {"event": "order.placed", "listeners": ["App\\\\Orders\\\\SendReceipt", "App\\\\Orders\\\\UpdateMetrics"]},
  {"event": "*", "listeners": ["App\\\\Audit\\\\RecordEvent"]}
]
```

## Commands and queries

Commands and queries have separate registries. Within each registry, the DTO/handler ID is unique, CLI aliases are unique, and an alias cannot collide with an ID. A single handler is registered for each ID; multiple listeners for one event are supported through the separate event-listener registry.

Command and query handlers implement `Flexi\\Contracts\\Interfaces\\HandlerInterface`. Exceptions from handlers and their dependencies propagate to the caller; the buses do not convert or suppress them. Registrations from inactive modules are absent from the next bus build.

The container build performs constructor-free preflight checks for configured routes, middleware, listeners, commands, and queries. It resolves service aliases and class definitions, then checks that known classes are loadable, instantiable, and compatible with the required PSR or Flexi interface. It does not invoke constructors, so constructor side effects remain lazy until the handler is first used.

For static service factories, a declared object return type is checked against the required interface. Factories without a named return type cannot be verified without executing them; their result is checked by the existing runtime `instanceof` guard when first resolved. Constructor dependency resolution errors are likewise reported on first use.
