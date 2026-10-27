# Historical Architecture Documents

This directory contains design notes and implementation reports written at different points in Flexi's development. A document listed here is kept for historical context and must not be treated as the current implementation contract.

## Current references

Use these documents for current behavior:

- [Module manifest](module-manifest.md), [module lifecycle](module-lifecycle.md), [module template](module-template.md), and [module recipes](module-recipes.md) for module structure and lifecycle.
- [Configuration load order](configuration-load-order.md), [service definitions](service-definitions.md), [HTTP routing](http-routing.md), and [message handlers](message-handlers.md) for configuration and runtime contracts.
- [Cache invalidation](cache-invalidation.md), [runtime requirements](runtime-requirements.md), and [security and environment](security-and-environment.md) for operations.
- [TODO modular architecture roadmap](TODO-modular-architecture-roadmap.md) for remaining work and deferred decisions.

## Superseded proposals and reports

| Document | Status | Current reference |
|---|---|---|
| [Core refactor architecture proposal](core-refactor-architecture-proposal.md) | Historical proposal. Its class locations and proposed module boundaries predate the current configuration-driven architecture. | Current references above. |
| [Core refactor visualization](core-refactor-visualization.md) | Historical architecture snapshot; diagram labels and component placement are not authoritative. | Current references above. |
| [Remaining classes analysis](remaining-classes-analysis.md) | Superseded location analysis; recommendations refer to the former `Domain/Classes` layout. | [HTTP routing](http-routing.md) and [service definitions](service-definitions.md). |
| [Middleware extraction analysis](middleware-extraction-analysis.md) | Historical in-progress analysis. The referenced core middleware directory is no longer present. | [HTTP routing](http-routing.md) and [module recipes](module-recipes.md). |
| [Persistence/session extraction analysis](persistence-session-extraction-analysis.md) | Superseded investigation; the referenced implementations are no longer in the core tree. | [Module lifecycle](module-lifecycle.md) and [module recipes](module-recipes.md). |
| [UI system modularization analysis](ui-system-modularization-analysis.md) | Historical decision record; its completion claim does not describe all current UI code. | [Module manifest](module-manifest.md) and [module lifecycle](module-lifecycle.md). |
| [Singleton factory reset analysis](singleton-factory-reset-analysis.md) | Superseded; the current `ContainerFactory` has no singleton instance or reset API. | [Service definitions](service-definitions.md). |
| [Extraction summary](EXTRACTION-SUMMARY.md) | Historical report of extraction work; module implementations may live in separate repositories. | [Module lifecycle](module-lifecycle.md). |
| [Automatic modular system implementation summary](IMPLEMENTATION-SUMMARY.md) | Historical report. Its local module inventory does not match this checkout. | [Module manifest](module-manifest.md) and [module lifecycle](module-lifecycle.md). |

The remaining migration and extraction reports should be consulted only for their specific historical change; verify any claimed file path, API, or test result against the current source before reusing it.
