# Minimal Flexi Module Template

This template uses the current `extra.flexi` manifest, Contracts v5 schemas, PSR-15 handlers and middleware, and an optional event listener. Keep it in a Composer package so Composer registers the package's PSR-4 autoloader when the module is installed.

## Package manifest

```json
{
  "name": "acme/flexi-module-example",
  "type": "flexi-module",
  "require": {
    "php": ">=7.4",
    "flexi/contracts": "^5.0"
  },
  "autoload": {
    "psr-4": {
      "Acme\\Flexi\\Example\\": "src/"
    }
  },
  "extra": {
    "flexi": {
      "schema-version": 1,
      "module-name": "Example",
      "compatibility": {"core": "^3.0"},
      "config-files": [
        "Config/services.json",
        "Config/routes.json",
        "Config/listeners.json"
      ],
      "provides": ["example.greeting"]
    }
  }
}
```

Set the PHP and core constraints to the versions the module actually supports. Composer installs the module's autoloader; Flexi reads only the configuration paths listed in `extra.flexi.config-files` and registers them only while the module is active.

## Service and route configuration

`Config/services.json` registers middleware under a stable container ID:

```json
{
  "services": [
    {
      "name": "example.response_header",
      "class": {"name": "Acme\\Flexi\\Example\\Http\\ResponseHeaderMiddleware"}
    }
  ]
}
```

`Config/routes.json` references the controller class and middleware service ID:

```json
{
  "routes": [
    {
      "name": "example.greeting",
      "path": "/example",
      "controller": "Acme\\Flexi\\Example\\Http\\GreetingHandler",
      "method": "GET",
      "middlewares": ["example.response_header"]
    }
  ]
}
```

`src/Http/GreetingHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Acme\Flexi\Example\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GreetingHandler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;

    public function __construct(ResponseFactoryInterface $responses)
    {
        $this->responses = $responses;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write('Hello from the Example module.');

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
```

`src/Http/ResponseHeaderMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Acme\Flexi\Example\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResponseHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Example-Module', 'active');
    }
}
```

The route matches the literal path `/example`. The first listed middleware is the outermost middleware. See [HTTP routing](http-routing.md) for current request, error, and override behavior.

## Optional event listener

`Config/listeners.json` is a JSON array, unlike the object-shaped services and routes files. Keep it empty if the module does not need event handling:

```json
[]
```

When the application has a meaningful event to handle, add an entry such as:

```json
[
  {"event": "account.created", "listeners": ["Acme\\Flexi\\Example\\AccountCreatedListener"]}
]
```

The listener implements `Flexi\Contracts\Interfaces\EventListenerInterface` and its `handle(EventInterface $event): void` method. Multiple listeners for an event run in stable registration order. Avoid constructor side effects; handlers and listeners are resolved when used, not preflighted at application startup.

## Validate and activate

Install the Composer package, validate module configuration, inspect the discovered logical name, then activate it:

```bash
composer require acme/flexi-module-example
php bin/console --command modules:validate
php bin/console --command modules:list
php bin/console --command modules:activate module_name=Example
```

Restart or rebuild the application process after activation so its router and service container include the module. The `modules:activate` command also integrates declared module environment defaults into the root `.env`; review the resulting file before deployment. For repeatable setup and removal, use [Module Recipes](module-recipes.md).
