# Middleware Extraction Analysis

> **Historical analysis:** The source paths and pending extraction decision below predate the current middleware pipeline. See [HTTP routing](http-routing.md) and [historical documentation](historical-documentation.md) for the current references.

**Date:** October 27, 2025
**Branch:** refactor-complete-psr-compatibility
**Status:** 🔍 ANALYSIS IN PROGRESS

## Executive Summary

The framework has two authentication middlewares currently located in `src/Infrastructure/Middlewares/`:
1. **AuthCheckMiddleware** - Session-based authentication check
2. **JWTAuthMiddleware** - JWT token validation

**Key Question:** Can these be extracted to `Contracts` as reusable components, or do they belong in a dedicated `modules/Auth/` module?

---

## Current Middleware Architecture

### Location
```
src/Infrastructure/Middlewares/
├── AuthCheckMiddleware.php     (49 lines)
└── JWTAuthMiddleware.php       (56 lines)
```

### PSR Standards Compliance

Both middlewares implement `Psr\Http\Server\MiddlewareInterface`:
- ✅ Follows PSR-15 standard
- ✅ Compatible with middleware chains
- ✅ Can work with any PSR-7 HTTP message

### Execution Flow

```
Request
   ↓
Route::throughMiddlewares() → Configures middlewares in HttpHandler
   ↓
HttpHandler::handle() [FINAL] → Executes middleware chain
   ↓
Middleware → process($request, $handler)
   ↓
Middleware → Returns response or calls $handler->handle()
   ↓
Next handler or controller logic
   ↓
Response
```

---

## Middleware 1: AuthCheckMiddleware

### Code Analysis

```php
class AuthCheckMiddleware implements MiddlewareInterface
{
    private SessionStorageInterface $session;
    private ResponseFactoryInterface $response_factory;

    public function __construct(
        SessionStorageInterface $session,
        ResponseFactoryInterface $response_factory
    ) {
        $this->session = $session;
        $this->response_factory = $response_factory;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (!$this->session->has('auth') || true !== $this->session->get('auth')) {
            $response = $this->response_factory->createResponse(401, 'Unauthorized');
            $response->getBody()->write('Unauthorized');
            return $response;
        }

        return $handler->handle($request);
    }
}
```

### Dependencies Analysis

| Dependency | Type | Location | Reusable |
|-----------|------|----------|----------|
| `SessionStorageInterface` | Interface (PSR-like) | Contracts ✅ | ✅ Yes |
| `ResponseFactoryInterface` | Interface (PSR-7) | PSR Package | ✅ Yes |
| `MiddlewareInterface` | Interface (PSR-15) | PSR Package | ✅ Yes |
| `ServerRequestInterface` | Interface (PSR-7) | PSR Package | ✅ Yes |
| `RequestHandlerInterface` | Interface (PSR-15) | PSR Package | ✅ Yes |

### Reusability Assessment

✅ **HIGHLY REUSABLE**
- Zero dependencies on core infrastructure
- Only depends on PSR standards and Contracts
- Generic session-based authentication logic
- Can be used in any PSR-15 compatible application

### Genericness Level: **95%**
- Implementation is generic (checks session `auth` flag)
- Only depends on abstractions
- No framework-specific logic
- No hardcoded business rules

---

## Middleware 2: JWTAuthMiddleware

### Code Analysis

```php
class JWTAuthMiddleware implements MiddlewareInterface
{
    private Configuration $configuration;
    private ResponseFactoryInterface $response_factory;

    public function __construct(
        Configuration $configuration,
        ResponseFactoryInterface $response_factory
    ) {
        $this->configuration = $configuration;
        $this->response_factory = $response_factory;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $this->response_factory->createResponse(401, 'Authorization header not found')
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $jwt = $matches[1];
        $key = $this->configuration->get('webhook_secret');

        try {
            $payload = JWT::decode($jwt, new Key($key, 'HS256'));
            $request = $request->withAttribute('payload', $payload);
        } catch (\LogicException|\UnexpectedValueException $e) {
            return $this->response_factory->createResponse(401, $e->getMessage());
        }

        return $handler->handle($request);
    }
}
```

### Dependencies Analysis

| Dependency | Type | Location | Reusable |
|-----------|------|----------|----------|
| `Configuration` | Class (Core) | src/Infrastructure | ⚠️ Core-specific |
| `ResponseFactoryInterface` | Interface (PSR-7) | PSR Package | ✅ Yes |
| `Firebase\JWT\JWT` | External library | composer.json | ✅ Yes |
| `Firebase\JWT\Key` | External library | composer.json | ✅ Yes |

### Problems with JWT Middleware

1. **Core Dependency**: Uses `Configuration` from core
   - Tight coupling to core
   - Can't be used standalone
   - Requires core configuration mechanism

2. **Mixed Concerns**:
   - Extracts JWT from Authorization header (reusable)
   - Decodes JWT with external library (reusable)
   - Stores payload in request attribute (generic)
   - BUT: Uses core Configuration to get secret key (non-reusable)

### Reusability Assessment

⚠️ **PARTIALLY REUSABLE (60%)**
- Generic JWT extraction and validation logic
- BUT depends on core Configuration class
- Could be made more reusable with interface injection

### How to Make It Fully Reusable

**Option A: Accept interface instead of Configuration**
```php
interface SecretProviderInterface
{
    public function getSecret(): string;
}

class JWTAuthMiddleware implements MiddlewareInterface
{
    private SecretProviderInterface $secret_provider;

    public function __construct(
        SecretProviderInterface $secret_provider,
        ResponseFactoryInterface $response_factory
    ) {
        $this->secret_provider = $secret_provider;
        $this->response_factory = $response_factory;
    }

    public function process(...): ResponseInterface
    {
        $key = $this->secret_provider->getSecret();
        // ... rest of logic
    }
}
```

Then both core's Configuration and modules can implement this interface.

### Genericness Level: **60%** (can be improved to 95%)

---

## HttpHandler: Middleware Management Infrastructure

### Location & Purpose

```php
// src/Infrastructure/Classes/HttpHandler.php
abstract class HttpHandler implements RequestHandlerInterface
{
    protected \SplQueue $queue;

    final public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->queue->isEmpty()) {
            return $this->getNextMiddleware()->process($request, $this);
        }
        return $this->process($request);
    }

    abstract protected function process(ServerRequestInterface $request): ResponseInterface;
}
```

### Reusability Assessment

✅ **HIGHLY REUSABLE - CANDIDATE FOR CONTRACTS**
- Pure middleware chain management
- Zero dependencies on framework specifics
- Generic Template Method pattern
- Could benefit other applications

### Why Extract to Contracts?

1. **Generic Middleware Infrastructure**
   - Not specific to authentication
   - Could be used for logging, caching, rate limiting
   - Foundational middleware pattern

2. **Reuse Across Modules**
   - Modules create their own middleware chains
   - Each module handler can extend HttpHandler

3. **Clean Core**
   - Move implementation detail to Contracts
   - Core only orchestrates buses, DI, router

---

## Decision Matrix

### Option A: Extract Both to Contracts

```
contracts/src/Classes/
├── HttpHandler.php                 ← Middleware infrastructure
├── Middlewares/
│   ├── AuthCheckMiddleware.php     ← Session-based auth
│   └── JWTAuthMiddleware.php       ← JWT validation
```

**Pros:**
- ✅ Highest code reuse
- ✅ Modules can extend/customize
- ✅ Clean core
- ✅ Generic patterns available to all

**Cons:**
- ❌ Contracts becomes "utilities" package
- ❌ Authentication middleware too specific for generic Contracts
- ❌ Business logic in shared package

**Assessment:** ⚠️ PARTIAL - Extract HttpHandler, NOT middleware

---

### Option B: Extract HttpHandler to Contracts, Auth Middlewares to modules/Auth

```
contracts/src/Classes/
├── HttpHandler.php                 ← Middleware infrastructure

modules/Auth/
├── Infrastructure/Middlewares/
│   ├── AuthCheckMiddleware.php     ← Session-based auth
│   └── JWTAuthMiddleware.php       ← JWT validation
├── Config/services.json
└── tests/
```

**Pros:**
- ✅ Middleware infrastructure in Contracts (reusable)
- ✅ Auth-specific logic in Auth module
- ✅ Clear separation of concerns
- ✅ Core stays clean
- ✅ Modules can have their own middleware variants

**Cons:**
- ❌ More folder structure
- ❌ Need to create Auth module

**Assessment:** ✅ BEST APPROACH

---

### Option C: Leave Everything in Core (Current State)

**Pros:**
- ✅ No changes needed
- ✅ Simpler structure

**Cons:**
- ❌ Core bloated with auth concerns
- ❌ Difficult to extend/customize auth
- ❌ HttpHandler pattern not reusable
- ❌ Violates Single Responsibility

**Assessment:** ❌ NOT RECOMMENDED

---

## Proposed Solution: Option B

### Phase 1: Extract HttpHandler to Contracts

1. Move `src/Infrastructure/Classes/HttpHandler.php` to `contracts/src/Classes/HttpHandler.php`
2. Update namespace: `Flexi\Infrastructure\Classes` → `Flexi\Contracts\Classes`
3. Update imports in all controllers/handlers in core and modules

### Phase 2: Create modules/Auth

Structure:
```
modules/Auth/
├── Config/
│   └── services.json
├── Infrastructure/
│   └── Middlewares/
│       ├── AuthCheckMiddleware.php
│       └── JWTAuthMiddleware.php
├── tests/
│   └── Infrastructure/
│       └── Middlewares/
│           ├── AuthCheckMiddlewareTest.php
│           └── JWTAuthMiddlewareTest.php
└── README.md
```

### Phase 3: Update JWTAuthMiddleware for Generic Use

Refactor to use `SecretProviderInterface`:

**Before:**
```php
class JWTAuthMiddleware
{
    private Configuration $configuration;

    public function __construct(Configuration $configuration, ...)
    {
        $this->configuration = $configuration;
    }

    // Inside process():
    $key = $this->configuration->get('webhook_secret');
}
```

**After:**
```php
interface SecretProviderInterface
{
    public function getSecret(): string;
}

class JWTAuthMiddleware
{
    private SecretProviderInterface $secret_provider;

    public function __construct(SecretProviderInterface $secret_provider, ...)
    {
        $this->secret_provider = $secret_provider;
    }

    // Inside process():
    $key = $this->secret_provider->getSecret();
}
```

Then create adapter:
```php
class ConfigurationSecretProvider implements SecretProviderInterface
{
    private Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function getSecret(): string
    {
        return $this->configuration->get('webhook_secret');
    }
}
```

**Benefits:**
- ✅ JWTAuthMiddleware becomes fully reusable
- ✅ Decoupled from core Configuration
- ✅ Other modules can provide different secret sources
- ✅ 95% genericness

### Phase 4: Update Core

Core `src/Config/services.json` will remove middleware definitions (like with Ui module).

Module `modules/Auth/Config/services.json` will define:
```json
{
  "services": [
    {
      "name": "Flexi\\Contracts\\Interfaces\\SecretProviderInterface",
      "class": {
        "name": "Flexi\\Modules\\Auth\\Infrastructure\\Adapters\\ConfigurationSecretProvider",
        "arguments": ["@Flexi\\Infrastructure\\Classes\\Configuration"]
      }
    },
    {
      "name": "auth_check_middleware",
      "class": {
        "name": "Flexi\\Modules\\Auth\\Infrastructure\\Middlewares\\AuthCheckMiddleware",
        "arguments": ["@Flexi\\Contracts\\Interfaces\\SessionStorageInterface", "@Psr\\Http\\Message\\ResponseFactoryInterface"]
      }
    },
    {
      "name": "jwt_auth_middleware",
      "class": {
        "name": "Flexi\\Modules\\Auth\\Infrastructure\\Middlewares\\JWTAuthMiddleware",
        "arguments": ["@Flexi\\Contracts\\Interfaces\\SecretProviderInterface", "@Psr\\Http\\Message\\ResponseFactoryInterface"]
      }
    }
  ]
}
```

### Phase 5: Create Interfaces in Contracts

If reusing SecretProvider in other modules:
```
contracts/src/Interfaces/
├── SecretProviderInterface.php
└── (existing interfaces)
```

---

## Implementation Impact

### Files to Move/Create

| Action | File | New Location |
|--------|------|--------------|
| MOVE | `src/Infrastructure/Classes/HttpHandler.php` | `contracts/src/Classes/HttpHandler.php` |
| CREATE | N/A | `modules/Auth/Infrastructure/Middlewares/AuthCheckMiddleware.php` |
| CREATE | N/A | `modules/Auth/Infrastructure/Middlewares/JWTAuthMiddleware.php` |
| CREATE | N/A | `modules/Auth/Infrastructure/Adapters/ConfigurationSecretProvider.php` |
| CREATE | N/A | `contracts/src/Interfaces/SecretProviderInterface.php` |
| DELETE | `src/Infrastructure/Middlewares/AuthCheckMiddleware.php` | (moved to Auth module) |
| DELETE | `src/Infrastructure/Middlewares/JWTAuthMiddleware.php` | (moved to Auth module) |

### Tests Impact

| Test | Current Location | New Location |
|------|-----------------|--------------|
| HttpHandlerTest | `tests/Infrastructure/Classes/` | Keep or reference from Auth module |
| AuthCheckMiddlewareTest | `tests/Infrastructure/Middlewares/` | `modules/Auth/tests/Infrastructure/Middlewares/` |
| JWTAuthMiddlewareTest | `tests/Infrastructure/Middlewares/` | `modules/Auth/tests/Infrastructure/Middlewares/` |

### Imports to Update

1. **Core Controllers** - Update to use `HttpHandler` from Contracts
2. **Test Files** - Update to reference new locations
3. **Route Config** - Reference Auth module middlewares
4. **services.json** - Move Auth service definitions

---

## Expected Test Results

- Before: 171 tests passing
- After: ~178-182 tests passing (new Auth module tests added)
- All existing tests should still pass ✅

---

## Recommendation

**IMPLEMENT OPTION B** ✅

### Benefits
1. ✅ HttpHandler pattern becomes reusable foundation
2. ✅ Auth logic encapsulated in dedicated module
3. ✅ JWTAuthMiddleware becomes fully generic
4. ✅ Core stays focused on orchestration
5. ✅ Modules can have own middleware variations
6. ✅ Aligns with established architecture pattern

### Complexity
- **Effort:** MEDIUM (3-4 hours)
- **Risk:** LOW (similar to previous refactorings)
- **Test Coverage:** HIGH (clear validation points)

---

## Next Steps

1. Approve/Reject this analysis
2. If approved, execute Phase 1-5 in order
3. Validate with full test suite
4. Document Auth module
5. Continue with remaining refactorings

---

**Analysis complete. Awaiting decision on middleware extraction strategy.**
