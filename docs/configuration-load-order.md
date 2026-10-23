# Configuration Load Order and Priority

Flexi uses a stable registration order so active modules produce the same configuration sequence regardless of detector or filesystem iteration order.

## File order

For each configuration type, the core configuration file is first when core configuration is included. Active modules normally follow in alphabetical order by logical name, without regard to case. The root Composer manifest can override this order with an optional sequence under `extra.flexi.module-load-order`:

```json
{
  "extra": {
    "flexi": {
      "module-load-order": ["Auth", "Billing", "Notifications"]
    }
  }
}
```

Only active modules are loaded. Active modules named in this sequence are placed first in the listed order; unknown or inactive names are ignored. Active modules not listed follow in alphabetical order. Names are matched without regard to case. The property must be a sequential list of unique, non-empty strings; invalid lists fail the build. Flexi reads and snapshots this setting on the first module configuration lookup for the runtime, so changes take effect on the next application build. In a local/vendor conflict, the hybrid detector first selects its local-priority representation, which contributes one module entry.

Within a module, the order of paths in `extra.flexi.config-files` is preserved. The current provider resolves one file per configuration type: it uses the first declared path whose basename matches `<type>.json`. A module should therefore declare no more than one file for each configuration type. Within a JSON file, definitions are processed in array order.

## Definition precedence

Loading order does not grant implicit replacement rights:

| Component | Precedence and conflict behavior |
|---|---|
| Services | Duplicate IDs fail. A decorator must name its target with `decorates` and use `@.inner` exactly once. Decoration chains follow those explicit target IDs, not file order. |
| Routes | Duplicate names and `METHOD path` pairs fail. An override must name the existing route with `overrides`, retain that route name, and be the only override for that name. |
| Commands and queries | Duplicate handler IDs and CLI aliases fail. No implicit priority or replacement is applied. |
| Event listeners | Listeners retain file order, then definition-array order, then listener-array order. All configured listeners currently have equal priority; the schema does not expose a priority field. For each event dispatch, listeners registered for the specific event run before wildcard (`*`) listeners. |

These rules make intentional replacement explicit and keep collisions actionable. They do not depend on which module the detector returns first.
