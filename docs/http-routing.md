# HTTP Routes and Middleware

The machine-readable route definition is owned by the [`flexi/contracts` routes schema](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.2.0/schemas/configuration/v1). It defines the HTTP method, path, route name, controller, query-parameter requirements, middleware IDs, and explicit route overrides.

## Current route behavior

- `path` is matched literally against the request URI path. The schema currently has no syntax for extracting path parameters; `parameters` describes required query/form values read from `$_GET` and `$_POST`.
- `controller` resolves to a PSR-15 `RequestHandlerInterface`. It does not need to extend Flexi's `HttpHandler` base class.
- Each `middlewares` string is resolved through the PSR-11 container. The resolved object must implement PSR-15 `MiddlewareInterface`; aliases and factories can be registered in `services.json`.
- Middleware runs in the array's declared order, with the first entry outermost. A middleware can short-circuit by returning a response without calling its next handler. Exceptions propagate through the chain to the application error handler.
- A request for an unknown path dispatches `RouteNotFoundEvent`; when no listener provides a response, the router returns a simple 404 response. If there are no registered routes at all, dispatch currently throws `Define at least one route` before the 404 flow.
- A method mismatch and a missing required query/form parameter currently raise `RuntimeException`. The router does not yet translate those cases into an HTTP 405 or 400 response.

## Configuration example

```json
{
  "routes": [
    {
      "name": "account.show",
      "path": "/account",
      "controller": "App\\Account\\ShowAccountHandler",
      "method": "GET",
      "parameters": [
        {"name": "id", "required": true}
      ],
      "middlewares": [
        "authentication",
        "request.logging"
      ]
    }
  ]
}
```

Route overrides keep the existing route name and declare that name in `overrides`. Duplicate route names and duplicate method/path pairs fail during router construction and include the contributing configuration files. The override behavior is described in [Configuration Load Order and Priority](configuration-load-order.md).

## Deferred route design

Parameterized path matching and arbitrary route metadata are not part of the current Contracts schema or router behavior. Whether to add those capabilities is an API design decision and remains open. Route grouping and prefix composition across application, module, and individual route levels also remain open until their precedence is agreed.
