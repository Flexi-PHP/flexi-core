# Flexi Framework

Flexi is a modular PHP framework designed to facilitate the development of scalable and maintainable applications. It
leverages Dependency Injection (DI), a flexible routing system, CQRS and an event-driven architecture.

## Table of Contents

- [Features](#features)
- [Structure](#structure)
- [Installation](#installation)
- [Configuration](#configuration)
    - [Services](#services)
    - [Routes](#routes)
    - [Events and Listeners](#events-and-listeners)
    - [Queries](#queries)
    - [Commands](#commands)
    - [Load order and priority](#load-order-and-priority)
- [Usage](#usage)
    - [Router](#router)
    - [Event System](#event-system)
    - [CQRS](#cqrs)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Modularity & Extensibility**: Flexi offers a modular architecture that promotes code organization and reusability. Its extensibility allows developers to customize and extend functionality according to specific project requirements. Each module is a self-contained Composer package with its own dependencies, allowing for easy installation, uninstallation, and version management.
- **Modular Architecture**: Flexi discovers local and Composer-installed modules. A module declares its configuration files in its Composer `extra.flexi` manifest and must be activated before its components are registered.
- **Dependency Injection**: Built-in support for Dependency Injection simplifies management and injection of dependencies, enhancing code maintainability and testability. DI in Flexi is implemented using a container that manages service with lazy loading instantiation.
- **Flexible Routing**: With a flexible routing system, developers can easily define and manage HTTP request handling, streamlining development of complex routing logic enriched with middleware support.
- **Event-Driven Architecture**: Flexi dispatches events to registered listeners, helping keep event producers separate from application reactions.
- **CQRS**: Separate command and query buses let applications organize request handling around explicit command and query handlers.
- **Validated JSON Configuration**: Services, routes, listeners, commands, and queries use JSON configuration validated against the schemas shipped by [`flexi/contracts`](https://github.com/Flexi-PHP/flexi-contracts/tree/v5.2.0/schemas/configuration/v1). That package is the single source of truth for the core and modules.

## Structure

- **Core**: the `src/` directory contains the core classes and interfaces that make up the framework.
- **Contracts**: shared interfaces and configuration schemas are provided by the Composer dependency `flexi/contracts`; this repository does not maintain a separate `contracts/` directory.
- **Modules**: the `modules/` directory contains all pieces of code that are not part of the core framework. Each module is a self-contained Composer package with its own `composer.json`, dependencies, controllers, services, and configuration files. Modules are plug-and-play and can be easily installed, uninstalled, or versioned independently.
- **Config**: the `src/Config/` and `modules/*/Config` directories contain configuration files for the framework (`routes`, `commands`, `queries`, `event listeners` and `services`).
- **Console**: the `bin/` directory contains the console application for CLI commands.

## Installation

The Flexi core repository is a Composer project. Clone the repository and install its dependencies:

```bash
git clone https://github.com/Flexi-PHP/flexi-core.git my-app
cd my-app
composer install
```

This sets up the core project. Flexi does not currently provide a separate application scaffolding command.

__Steps after install__

To get started with Flexi Framework, ensure you:

- Configure your web server to point to the `public` directory in the root of the project.
- Deny direct access to any directory that is not the `public` directory.
- Serve static files directly and route all other requests to the `index.php` file in the `public` directory.
- Set up the `.env` file with the necessary environment variables for your application.

After setup, browse to the URL of your application to see the welcome page. If you use the default configuration
with Docker, you can access the application at http://localhost:8080.

## Module Management

Modules are discovered from the local `modules/` directory and Composer's installed packages. Each module declares its identity and configuration files in `extra.flexi` in its `composer.json`; only active modules contribute registrations. Before the default container build, Flexi checks declared core compatibility, required active module dependencies, their version constraints, and circular dependencies. See [the module manifest](docs/module-manifest.md) and [module lifecycle](docs/module-lifecycle.md) for the current format and behavior.

For example, a module can declare its routes and services like this:

```json
{
  "name": "acme/flexi-module-account",
  "type": "flexi-module",
  "require": {"php": ">=7.4", "flexi/contracts": "^5.0"},
  "autoload": {"psr-4": {"Acme\\Account\\": "src/"}},
  "extra": {
    "flexi": {
      "schema-version": 1,
      "module-name": "Account",
      "compatibility": {"core": "^3.0"},
      "config-files": ["Config/services.json", "Config/routes.json"],
      "provides": ["account.profile"]
    }
  }
}
```

### Available Commands

The available module console commands are defined in [`src/Config/commands.json`](src/Config/commands.json). Install package dependencies with Composer, then use the registered `modules:list`, `modules:status`, `modules:activate`, `modules:deactivate`, `modules:info`, `modules:validate`, and `modules:env-update` commands as appropriate. The module lifecycle documentation describes which behaviors are implemented and which remain roadmap items.

## Configuration

Configuration for Flexi is managed through JSON files. These files define services, routes, events, queries, and
commands used within your application.

### Services

Services are defined in `services.json`. Module configuration files are selected by the core from the module manifest; file discovery does not use `glob` entries inside JSON.

#### Example

```json
{
  "services": [
    {
      "name": "App\\Infrastructure\\Mailer",
      "class": {"name": "App\\Infrastructure\\SmtpMailer", "arguments": ["ENV.MAILER_DSN"]}
    },
    {
      "name": "mailer",
      "alias": "App\\Infrastructure\\Mailer"
    }
  ]
}
```

__Note:__

- Each service has a unique `name`; an `alias` definition maps a service ID to another service ID.
- Arguments prefixed with `@` are references to other services (can be alias as well)
- Arguments prefixed with `ENV.` are references to environment variables.
- Quoted strings without `@` or `ENV.` prefixes are treated as string values.
- All other values are treated as the standard json_decode values.
- Modules list configuration paths in `extra.flexi.config-files`; the core loads files from active modules.

#### Service decoration

Modules can wrap a service while keeping its original service ID. Give the decorator a unique ID, declare the target with `decorates`, and pass `@.inner` exactly once in its constructor or factory arguments. Decoration chains must be explicit: a second decorator targets the first decorator's ID. Duplicate IDs, missing targets, and ambiguous decoration are rejected during container construction.

```json
{
  "services": [
    {
      "name": "App\\Logging\\LoggedMailer",
      "decorates": "App\\Contracts\\MailerInterface",
      "class": {
        "name": "App\\Logging\\LoggedMailer",
        "arguments": ["@.inner", "@logger"]
      }
    }
  ]
}
```

The decorated target continues to be resolved through its original ID. The `@.inner` token is reserved for service decorator arguments.

### Routes

Routes are defined in `routes.json`. Each route specifies a name, path, HTTP method, and controller. Module route files are listed in the module manifest.

#### Example

```json
{
  "routes": [
    {
      "name": "health",
      "path": "/health",
      "method": "GET",
      "controller": "App\\Http\\HealthController",
      "parameters": [],
      "middlewares": ["App\\Http\\Middleware\\AuthMiddleware"]
    },
    {
      "name": "account.profile",
      "path": "/account/profile",
      "method": "GET",
      "controller": "App\\Http\\ProfileController",
      "parameters": [{"name": "locale", "required": true}]
    }
  ]
}
```

__Note:__

- Controllers must implement `Psr\Http\Server\RequestHandlerInterface`. Route middleware is applied by the router when the controller extends `Flexi\Contracts\Classes\HttpHandler`.
- The optional `parameters` key declares required query or form parameters; it does not define path placeholders.
- The optional `middlewares` key is used to define the middlewares that should be executed before the controller. They are executed in the order they are defined and can stop the execution chain if they return a response directly or pass the request to the next middleware.
- Modules list route configuration files in `extra.flexi.config-files`; the core loads files from active modules.

Routes with duplicate names or `METHOD path` pairs fail at startup. A module can intentionally replace a route by setting `overrides` to the existing route name; the replacement must keep that same name. Routes sharing a path with different HTTP methods are supported.

```json
{
  "routes": [
    {
      "name": "account.profile",
      "overrides": "account.profile",
      "path": "/account/profile",
      "method": "GET",
      "controller": "App\\Account\\ProfileController"
    }
  ]
}
```

### Events and Listeners

Events and listeners are defined in the `listeners.json` file. This file maps events to their corresponding listeners.

#### Example

```json
[
  {
    "event": "App\\Order\\OrderPlaced",
    "listeners": ["App\\Order\\SendConfirmationEmail"]
  },
  {
    "event": "*",
    "listeners": ["App\\Observability\\LogEvents"]
  }
]
```

__Note:__

- The `event` key can be a specific event name or a wildcard `*` to listen to all events.
- The listener class should implement `Flexi\Contracts\Interfaces\EventListenerInterface` or extend the `Flexi\Contracts\Classes\EventListener` base class. Its `handle()` method receives an `EventInterface` and returns `void`.
- Module listener files are declared in `extra.flexi.config-files`; do not add glob objects to listener JSON files.

### Queries

Queries are defined in the `queries.json` file. Each query handler is mapped to a specific DTO and an optional CLI
alias.

#### Example

```json
{
  "handlers": [
    {
      "id": "App\\Catalog\\GetProductQuery",
      "cli_alias": "catalog:product",
      "handler": "App\\Catalog\\GetProductHandler"
    }
  ]
}
```

__Note:__

- Handlers implement `Flexi\Contracts\Interfaces\HandlerInterface`; the query identifier is the DTO class name. Module query files are declared in `extra.flexi.config-files`.

### Commands

Commands are defined similarly to queries in the `commands.json` file.

#### Example

```json
{
  "handlers": [
    {
      "id": "App\\Order\\PlaceOrderCommand",
      "cli_alias": "orders:place",
      "handler": "App\\Order\\PlaceOrderHandler"
    }
  ]
}
```

__Note:__

- Handlers implement `Flexi\Contracts\Interfaces\HandlerInterface`; the command identifier is the DTO class name. Module command files are declared in `extra.flexi.config-files`.

### Load Order and Priority

The core configuration loads first, followed by active modules sorted by logical name. You can override the module order in the root Composer manifest with `extra.flexi.module-load-order`; active modules not listed remain alphabetically ordered, while inactive and unknown names are ignored. Definitions retain their JSON array order. Flexi does not use implicit priorities to replace services, routes, handlers, or listeners: conflicts fail unless the service decorator or route override rules explicitly permit replacement. Listener priority is currently fixed; specific-event listeners run before wildcard listeners. See [Configuration Load Order and Priority](docs/configuration-load-order.md) for the complete rules and an example.

## Usage

### Router

The `Router` class is responsible for managing routes and dispatching requests to the appropriate controllers.

#### Example Usage

```php
use Flexi\Infrastructure\Http\Router;

function getHomePath(Router $router): string
{
    $route = $router->getByName('home');

    return $route->getPath();
}
```

### Event System

The event system in Flexi is based on the EventBus pattern. Events are dispatched to listeners which can handle them
accordingly.

#### Example

```php
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Domain\Events\Event;

function dispatchHealthCheck(EventBusInterface $eventBus): void
{
    $event = new Event('health-check', 'health-check endpoint', ['from' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    $eventBus->dispatch($event);
}
```

### CQRS

Flexi implements the CQRS pattern with separate handling for commands and queries.

#### Command Example

```php
use Flexi\Infrastructure\Bus\CommandBus;

// Assume $command is a class that implements the DTOInterface
$commandBus->execute($command);
```

#### Query Example

```php
use Flexi\Infrastructure\Bus\QueryBus;

// Assume $query is a class that implements the DTOInterface
$result = $queryBus->execute($query);
```

### Controllers and Response

The response is a PSR-7 response object that can be returned from a controller or middleware. Controllers that extend `Flexi\Contracts\Classes\HttpHandler` can build responses with `createResponse()`. Its constructor requires a `Psr\Http\Message\ResponseFactoryInterface`, supplied by the container. Flexi uses `GuzzleHttp\Psr7\HttpFactory` as the default factory.

The `HttpHandler` abstract class implements a Template Method pattern that automatically manages the middleware chain execution. Controllers only need to implement the `process()` method with their specific business logic, while the framework handles middleware orchestration automatically.

#### Basic Controller Example

```php
namespace Flexi\Modules\Home\Infrastructure\Controllers;

use Flexi\Contracts\Classes\HttpHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthenticatedController extends HttpHandler
{
    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        parent::__construct($responseFactory);
    }

    protected function process(ServerRequestInterface $request): ResponseInterface
    {
        // This method is called automatically after all middlewares have been processed
        $response = $this->createResponse();
        $response->getBody()->write('Authorized');

        return $response;
    }
}
```

#### Key Points

- **No manual middleware handling**: The `handle()` method is `final` and manages the middleware chain automatically
- **Focus on business logic**: Controllers only implement the `process()` method with their specific logic
- **Dependency injection**: Use constructor injection to receive dependencies from the DI container
- **Template Method pattern**: The framework ensures middlewares are executed before your controller logic
- **PSR-7 compliance**: All controllers work with PSR-7 request/response objects
- **Error handling**: Return appropriate HTTP status codes using `createResponse($code, $reasonPhrase)`

### Middlewares

Middlewares are classes that can be executed before the controller. They can modify the request, response or stop the execution chain. Middlewares should implement the `Psr\Http\Server\MiddlewareInterface` interface.

#### Middleware Implementation Example

```php
namespace Flexi\Infrastructure\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthCheckMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Perform authentication logic here and stop the execution chain if necessary
        // or pass the request
        return $handler->handle($request);
    }
}
```

## Testing

Flexi includes a comprehensive test suite using PHPUnit. Tests use a separate environment configuration to ensure isolation from development and production environments.

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit tests/

# Run specific test file
./vendor/bin/phpunit tests/Infrastructure/TestEnvironmentTest.php

# Run with coverage
./vendor/bin/phpunit tests/ --coverage-html coverage/
```

### Test Environment

Tests automatically use `.env.testing` configuration file with test-specific settings:

- **Synchronous event dispatch**: Events are dispatched synchronously for predictable testing
- **In-memory cache**: Faster tests without disk I/O
- **Separate logs**: Test logs go to `var/logs/test.log`
- **Isolated cache**: Test cache uses `var/cache/test/` directory

To customize test environment:

1. Copy `.env.testing.example` to `.env.testing`
2. Modify values as needed
3. Tests will automatically use these values

For more details, see [tests/README.md](tests/README.md).

### Writing Tests

```php
<?php

namespace Flexi\Test\YourNamespace;

use PHPUnit\Framework\TestCase;

class YourTest extends TestCase
{
    public function testSomething(): void
    {
        // Test environment variables from .env.testing are automatically loaded
        $this->assertTrue(true);
    }
}
```

## Documentation

The documentation is available online at [https://flexi.cubadevops.com](https://flexi.cubadevops.com) (Under construction and not yet available).

## Contributing

Contributions are welcome! Please submit a pull request or open an issue to discuss any changes you would like to make.

## License

**Flexi** is open-source software licensed under the [MIT license](LICENSE).
