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
- [Usage](#usage)
    - [Router](#router)
    - [Event System](#event-system)
    - [CQRS](#cqrs)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Modularity & Extensibility**: Flexi offers a modular architecture that promotes code organization and reusability. Its extensibility allows developers to customize and extend functionality according to specific project requirements. Each module is a self-contained Composer package with its own dependencies, allowing for easy installation, uninstallation, and version management.
- **Automatic Module Discovery**: Modules are automatically discovered and registered. Simply create a module in the `modules/` directory with a `composer.json` file, run `composer modules:sync`, and it's ready to use.
- **Dependency Injection**: Built-in support for Dependency Injection simplifies management and injection of dependencies, enhancing code maintainability and testability. DI in Flexi is implemented using a container that manages service with lazy loading instantiation.
- **Flexible Routing**: With a flexible routing system, developers can easily define and manage HTTP request handling, streamlining development of complex routing logic enriched with middleware support.
- **Event-Driven Architecture**: Flexi's event-driven architecture enables asynchronous handling of events by event listeners, fostering loose coupling and flexibility in application design.
- **CQRS & Scalability**: Support for Command Query Responsibility Segregation (CQRS) promotes scalability and performance optimization. Combined with features like asynchronous processing, Flexi facilitates the development of scalable applications capable of handling high traffic and data loads.
- **Configuration via JSON**: Configuration in Flexi is managed through JSON files, making it easy to define services, routes, events, queries, and commands. This approach simplifies configuration management and promotes consistency across projects. Json Schema will be implemented to validate the configuration files.

## Structure

- **Core**: the `src/` directory contains the core classes and interfaces that make up the framework.
- **Contracts**: the `contracts/` directory contains shared interfaces and base classes used by both core and modules, ensuring loose coupling and standardization.
- **Modules**: the `modules/` directory contains all pieces of code that are not part of the core framework. Each module is a self-contained Composer package with its own `composer.json`, dependencies, controllers, services, and configuration files. Modules are plug-and-play and can be easily installed, uninstalled, or versioned independently.
- **Config**: the `src/Config/` and `modules/*/Config` directories contain configuration files for the framework (`routes`, `commands`, `queries`, `event listeners` and `services`).
- **Console**: the `bin/` directory contains the console application for CLI commands.

## Installation

You can install Flexi Framework using Composer:

```bash
composer create-project cubadevops/flexi my-app
```

This command creates a minimal ready boilerplate application in the `my-app` directory.

__Steps after install__

To get started with Flexi Framework, ensure you:

- Configure your web server to point to the `public` directory in the root of the project.
- Deny direct access to any directory that is not the `public` directory.
- Serve static files directly and route all other requests to the `index.php` file in the `public` directory.
- Set up the `.env` file with the necessary environment variables for your application.

After setup, browse to the URL of your application to see the welcome page. If you use the default configuration
with Docker, you can access the application at http://localhost:8080.

## Module Management

Flexi features an automatic module discovery system that makes managing modules simple and efficient.

### Available Commands

```bash
# Sync all modules (auto-discover and register)
composer modules:sync

# List all available modules
composer modules:list

# Get detailed information about a module
php bin/console modules:info Auth

# Validate module configuration
composer modules:validate

# Install a module
php bin/console modules:install ModuleName

# Uninstall a module
php bin/console modules:uninstall ModuleName
```

### Creating a New Module

```bash
# 1. Create module structure
mkdir -p modules/MyModule/{Domain,Infrastructure,Config}

# 2. Create composer.json
cat > modules/MyModule/composer.json << 'EOF'
{
  "name": "cubadevops/flexi-module-mymodule",
  "version": "1.0.0",
  "type": "flexi-module",
  "require": {
    "php": ">=7.4",
    "cubadevops/flexi-contracts": "@dev"
  },
  "autoload": {
    "psr-4": {
      "Flexi\\Modules\\MyModule\\": ""
    }
  }
}
EOF

# 3. Sync modules
composer modules:sync
```

That's it! Your module is now installed and ready to use. Changes to module code are immediately reflected thanks to symlinks.

### How It Works

1. Each module has its own `composer.json` defining its package name and dependencies
2. The `modules:sync` command scans the `modules/` directory and auto-discovers modules
3. Composer creates symlinks from `vendor/cubadevops/flexi-module-*` to `modules/*`
4. All dependencies are managed centrally by Composer
5. Modules can be versioned, published to Git repositories, and shared across projects

For detailed documentation, see:
- [Automatic Module System](docs/modular-system-automatic.md)
- [Modules README](modules/README.md)

## Configuration

Configuration for Flexi is managed through JSON files. These files define services, routes, events, queries, and
commands used within your application.

### Services

Services are defined in the `services.json` file located in the `Config` directory. This file outlines how each service
should be instantiated, either directly or via factory methods.

#### Example

```json
{
  "services": [
    {
      "name": "Flexi\\Infrastructure\\Classes\\Configuration",
      "factory": {
        "class": "Flexi\\Infrastructure\\Factories\\ConfigurationFactory",
        "method": "getInstance",
        "arguments": []
      }
    },
    {
      "name": "session",
      "alias": "Flexi\\Infrastructure\\Session\\NativeSessionStorage"
    },
    {
      "name": "logger",
      "class": {
        "name": "Flexi\\Contracts\\Classes\\PsrLogger",
        "arguments": [
          "@Flexi\\Infrastructure\\Persistence\\InFileLogRepository"
        ]
      }
    },
    {
      "glob": "/modules/*/Config/services.json"
    }
  ]
}
```

__Note:__

- The `alias` key is used to reference services by a different name.
- Arguments prefixed with `@` are references to other services (can be alias as well)
- Arguments prefixed with `ENV.` are references to environment variables.
- Quoted strings without `@` or `ENV.` prefixes are treated as string values.
- All other values are treated as the standard json_decode values.
- The `glob` key is used to include services from `modules`.

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

Routes are defined in the `routes.json` file. Each route specifies the HTTP method, path, and the controller that should
handle the request.

#### Example

```json
{
  "routes": [
    {
      "name": "health",
      "path": "/health",
      "method": "GET",
      "controller": "Flexi\\Infrastructure\\Controllers\\HealthController",
      "parameters": [],
      "middlewares": [
        "Flexi\\Infrastructure\\Middlewares\\AuthCheckMiddleware"
      ]
    },
    {
      "name": "404",
      "path": "/not-found",
      "method": "GET",
      "controller": "Flexi\\Infrastructure\\Controllers\\NotFoundController"
    },
    {
      "glob": "/modules/*/Config/routes.json"
    }
  ]
}
```

__Note:__

- Controllers should implement the `Psr\Http\Server\RequestHandlerInterface` interface if you want attach middlewares. However they must have a `handle` method that receives a `Psr\Http\Message\ServerRequestInterface` and returns a `Psr\Http\Message\ResponseInterface`.
- The optional `parameters` key is used to define how many params should be passed to the request and if they are required.
- The optional `middlewares` key is used to define the middlewares that should be executed before the controller. They are executed in the order they are defined and can stop the execution chain if they return a response directly or pass the request to the next middleware.
- The `glob` key is used to include additional route definitions from `modules`.

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
    "event": "*",
    "listeners": [
      "Flexi\\Application\\EventListeners\\LoggerEventListener"
    ]
  },
  {
    "glob": "/modules/*/Config/listeners.json"
  }
]
```

__Note:__

- The `event` key can be a specific event name or a wildcard `*` to listen to all events.
- The listener class should implement `Flexi\Contracts\Interfaces\EventListenerInterface` or extend the `Flexi\Contracts\Classes\EventListener` base class. Its `handle()` method receives an `EventInterface` and returns `void`.

### Queries

Queries are defined in the `queries.json` file. Each query handler is mapped to a specific DTO and an optional CLI
alias.

#### Example

```json
{
  "handlers": [
    {
      "id": "Flexi\\Domain\\DTO\\EmptyVersionDTO",
      "cli_alias": "version",
      "handler": "Flexi\\Application\\UseCase\\Health"
    },
    {
      "glob": "/modules/*/Config/queries.json"
    }
  ]
}
```

__Note:__

- Handlers should implement the `Flexi\Domain\Interfaces\HandlerInterface` interface.

### Commands

Commands are defined similarly to queries in the `commands.json` file.

#### Example

```json
{
  "handlers": [
    {
      "glob": "/modules/*/Config/commands.json"
    }
  ]
}
```

__Note:__

- Handlers should implement the `Flexi\Domain\Interfaces\HandlerInterface` interface.

## Usage

### Router

The `Router` class is responsible for managing routes and dispatching requests to the appropriate controllers.

#### Example Usage

```php
use Flexi\Domain\Classes\Router;
use Psr\Http\Message\ServerRequestInterface;
use Flexi\Infrastructure\Factories\ContainerFactory;

/** @var Router $router */
$router = ContainerFactory::getInstance()->get(Router::class); // or use router alias
$route = $router->getByName('home'); // Get a route by name
$route->getPath(); // Get the path of the route to pass to the template
```

### Event System

The event system in Flexi is based on the EventBus pattern. Events are dispatched to listeners which can handle them
accordingly.

#### Example

```php
use Flexi\Domain\Interfaces\EventBusInterface;
use Flexi\Domain\Classes\Event;
use Flexi\Application\UseCase\Health;
use Flexi\Infrastructure\Factories\ContainerFactory;
use Flexi\Infrastructure\Bus\EventBus;

$eventBus = ContainerFactory::getInstance()->get(EventBus::class);
$event = new Event('health-check', Health::class, ['from' => $_SERVER['REMOTE_ADDR']);
$eventBus->notify($event);
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

The response is a PSR-7 response object that can be returned from a controller or middleware. Controllers that extend the `Flexi\Infrastructure\Classes\HttpHandler` have an easy way to build responses using the `createResponse` method. If you don't extend the `HttpHandler` you can use a factory that implements `Psr\Http\Message\ResponseFactoryInterface` interface to build the response. Flexi uses the `GuzzleHttp\Psr7\HttpFactory` as default factory.

The `HttpHandler` abstract class implements a Template Method pattern that automatically manages the middleware chain execution. Controllers only need to implement the `process()` method with their specific business logic, while the framework handles middleware orchestration automatically.

#### Basic Controller Example

```php
namespace Flexi\Modules\Home\Infrastructure\Controllers;

use Flexi\Infrastructure\Classes\HttpHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthenticatedController extends HttpHandler
{
    protected function process(ServerRequestInterface $request): ResponseInterface
    {
        // This method is called automatically after all middlewares have been processed
        $response = $this->createResponse();
        $response->getBody()->write('Authorized');

        return $response;
    }
}
```

#### Controller with Dependencies and Business Logic

```php
namespace Flexi\Infrastructure\Controllers;

use Flexi\Infrastructure\Bus\QueryBus;
use Flexi\Application\Queries\GetVersionQuery;
use Flexi\Infrastructure\Classes\HttpHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HealthController extends HttpHandler
{
    private QueryBus $query_bus;

    public function __construct(QueryBus $query_bus)
    {
        $this->query_bus = $query_bus;
        parent::__construct(); // Always call parent constructor
    }

    protected function process(ServerRequestInterface $request): ResponseInterface
    {
        // Execute business logic using CQRS pattern
        $version = $this->query_bus->execute(new GetVersionQuery());

        $response = $this->createResponse();
        $response->getBody()->write('Version: ' . $version);

        return $response;
    }
}
```

#### Controller with Template Rendering

```php
namespace Flexi\Infrastructure\Controllers;

use Flexi\Domain\Interfaces\TemplateEngineInterface;
use Flexi\Infrastructure\Classes\HttpHandler;
use Flexi\Contracts\Classes\Traits\FileHandlerTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class NotFoundController extends HttpHandler
{
    use FileHandlerTrait;

    private TemplateEngineInterface $html_render;

    public function __construct(TemplateEngineInterface $html_render)
    {
        $this->html_render = $html_render;
        parent::__construct();
    }

    protected function process(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->html_render->render(
            $this->normalize('./src/Infrastructure/Ui/Templates/404.html'),
            ['request' => $request->getUri()->getPath()]
        );

        $response = $this->createResponse(404, 'Not Found');
        $response->getBody()->write($body);

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
