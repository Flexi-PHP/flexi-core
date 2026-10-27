# Analysis: InFileLogRepository & NativeSessionStorage Extraction

> **Superseded analysis:** The extraction decision has since moved beyond this in-progress note; the referenced implementations are no longer in the core tree. See [historical documentation](historical-documentation.md) for current module references.

**Date:** October 27, 2025
**Status:** 🔍 ANALYSIS IN PROGRESS

## Executive Summary

Evaluating whether to extract two default implementations:
1. **InFileLogRepository** - File-based log persistence
2. **NativeSessionStorage** - PHP native session storage

**Decision Needed:** Move to Contracts or to dedicated modules?

---

## Component 1: InFileLogRepository

### Location & Code Analysis
```
Current: src/Infrastructure/Persistence/InFileLogRepository.php
Interface: contracts/src/Interfaces/LogRepositoryInterface.php
```

**Code Structure:**
- 53 lines
- Implements `LogRepositoryInterface`
- Uses `FileHandlerTrait` from Contracts
- Concrete: File-based log storage

**Dependencies:**
```php
- FileHandlerTrait (from Contracts) ✅ Generic
- LogInterface (from Contracts) ✅ Generic
- LogRepositoryInterface (from Contracts) ✅ Generic
- PHP built-in functions (file operations) ✅ Generic
```

### Reusability Assessment

**Genericness:** ✅ 90% REUSABLE
- Writes logs to file system
- Uses standard file operations
- No framework-specific logic
- Pure file persistence

**Framework Specificity:** ❌ ZERO
- No dependencies on Configuration
- No dependencies on core services
- No dependencies on specific modules

**Customizability:** ✅ HIGH
- Can be replaced with DatabaseLogRepository
- Can be extended for compression, rotation, etc.
- Format string pattern is flexible

### Current Usage

```php
// In src/Config/services.json
{
  "name": "Flexi\\Infrastructure\\Persistence\\InFileLogRepository",
  "class": {
    "name": "Flexi\\Infrastructure\\Persistence\\InFileLogRepository",
    "arguments": [
      "ENV.log_file_path",
      "ENV.log_format"
    ]
  }
}
```

Used by: `PsrLogger` (in Contracts) for actual log persistence

---

## Component 2: NativeSessionStorage

### Location & Code Analysis
```
Current: src/Infrastructure/Session/NativeSessionStorage.php
Interface: contracts/src/Interfaces/SessionStorageInterface.php
```

**Code Structure:**
- 91 lines
- Implements `SessionStorageInterface`
- Also implements `ArrayAccess` interface
- Concrete: PHP native session storage

**Dependencies:**
```php
- LoggerInterface (PSR-3) ✅ Generic
- PHP $_SESSION superglobal ✅ Generic
- session_* PHP functions ✅ Generic
```

### Reusability Assessment

**Genericness:** ✅ 95% REUSABLE
- Uses only PHP native session handling
- PSR-3 logger for error handling
- ArrayAccess for convenient API
- Pure session abstraction

**Framework Specificity:** ❌ ZERO
- No dependencies on Configuration
- No dependencies on core services
- No dependencies on specific modules

**Customizability:** ✅ HIGH
- Can be replaced with Redis-based session
- Can be extended for encryption
- Can be swapped for JWT-based sessions

### Current Usage

```php
// In src/Config/services.json
{
  "name": "Flexi\\Infrastructure\\Session\\NativeSessionStorage",
  "class": {
    "name": "Flexi\\Infrastructure\\Session\\NativeSessionStorage",
    "arguments": [
      "@logger",
      {
        "name": "PHPSESSID",
        "cookie_lifetime": 0,
        // ... session options
      }
    ]
  }
}
```

Used by: Core routing and middleware for session management

---

## Decision Matrix

### Option A: Extract Both to Contracts

**For InFileLogRepository:**
```
Pros:
✅ Generic file-based logging
✅ Pair with LogRepositoryInterface
✅ Reusable in any PHP application
✅ Default implementation in Contracts

Cons:
❌ Contracts becomes "implementation" package
❌ File I/O operations in Contracts
❌ Tight with PsrLogger
```

**For NativeSessionStorage:**
```
Pros:
✅ Generic PHP session handling
✅ Pair with SessionStorageInterface
✅ Reusable in any PHP application
✅ Default implementation in Contracts

Cons:
❌ Contracts becomes "implementation" package
❌ Session handling is infrastructure concern
❌ Not all apps use sessions
```

**Assessment:** ⚠️ PROBLEMATIC - Contracts should be contracts + minimal utilities, not implementations

---

### Option B: Extract to Dedicated Modules

**For InFileLogRepository:**
```
modules/Logging/
├── Infrastructure/
│   ├── Persistence/
│   │   └── InFileLogRepository.php
│   └── Adapters/
│       └── (alternative implementations)
├── Config/services.json
└── tests/
    └── ...
```

**For NativeSessionStorage:**
```
modules/Session/
├── Infrastructure/
│   ├── Session/
│   │   └── NativeSessionStorage.php
│   └── Adapters/
│       └── (alternative implementations)
├── Config/services.json
└── tests/
    └── ...
```

**Assessment:** ✅ CLEANER - Dedicated modules with single concern

---

### Option C: InFileLogRepository → Contracts, NativeSessionStorage → modules/Session

**Rationale:**
- InFileLogRepository pairs directly with PsrLogger (both in Contracts)
- NativeSessionStorage is optional infrastructure (not all apps need it)
- More nuanced approach

**Assessment:** 🤔 HYBRID - Mixed approach, less cohesive

---

### Option D: Keep Both in Core

**Assessment:** ❌ NOT RECOMMENDED - Violates modularization principles

---

## Detailed Comparison

| Aspect | InFileLogRepository | NativeSessionStorage |
|--------|-------------------|----------------------|
| **Genericness** | 90% | 95% |
| **Framework Specific** | NO | NO |
| **Paired Interface** | LogRepositoryInterface | SessionStorageInterface |
| **File Operations** | YES | NO |
| **PHP Native APIs** | file_* | session_* |
| **Optional?** | YES (can use DB) | YES (can use Redis) |
| **Used by Core?** | PsrLogger (Contracts) | Core routing |
| **Test Coverage** | YES (exists) | YES (exists) |
| **Alternative Impls** | DatabaseLogRepository | RedisSessionStorage |

---

## Recommendation: **OPTION B (BEST)**

### Extract Both to Dedicated Modules

**Why:**
1. **Clear Separation** - Logging and Session are distinct concerns
2. **Modularity** - Each can be swapped/replaced independently
3. **Contracts Purity** - Keep Contracts as contracts + minimal utilities
4. **Extensibility** - Easy to add alternative implementations (Redis, DB, etc.)
5. **Optional** - Apps can choose to use these modules or provide alternatives

### Implementation Plan

#### Phase 1: Create modules/Logging

```
modules/Logging/
├── Infrastructure/Persistence/
│   └── InFileLogRepository.php
├── Config/services.json
├── tests/Infrastructure/Persistence/
│   └── InFileLogRepositoryTest.php
└── README.md
```

**services.json:**
```json
{
  "services": [
    {
      "name": "Flexi\\Contracts\\Interfaces\\LogRepositoryInterface",
      "class": {
        "name": "Flexi\\Modules\\Logging\\Infrastructure\\Persistence\\InFileLogRepository",
        "arguments": ["ENV.log_file_path", "ENV.log_format"]
      }
    }
  ]
}
```

#### Phase 2: Create modules/Session

```
modules/Session/
├── Infrastructure/Session/
│   └── NativeSessionStorage.php
├── Config/services.json
├── tests/Infrastructure/Session/
│   └── NativeSessionStorageTest.php
└── README.md
```

**services.json:**
```json
{
  "services": [
    {
      "name": "Flexi\\Contracts\\Interfaces\\SessionStorageInterface",
      "class": {
        "name": "Flexi\\Modules\\Session\\Infrastructure\\Session\\NativeSessionStorage",
        "arguments": [
          "@logger",
          { /* session options */ }
        ]
      }
    }
  ]
}
```

#### Phase 3: Update Core services.json

Remove implementations, keep glob pattern:
```json
{
  "glob": "./modules/*/Config/services.json"
}
```

#### Phase 4: Update tests

Move:
```
tests/Infrastructure/Persistence/InFileLogRepositoryTest.php
  → modules/Logging/tests/Infrastructure/Persistence/

tests/Infrastructure/Session/NativeSessionStorageTest.php
  → modules/Session/tests/Infrastructure/Session/
```

---

## Alternative Implementations (Future)

### Logging Module could also include:
```
modules/Logging/Infrastructure/Persistence/
├── InFileLogRepository.php          (current)
├── DatabaseLogRepository.php        (future: logs to DB)
├── S3LogRepository.php              (future: logs to AWS S3)
└── LogstashLogRepository.php        (future: ELK stack)
```

### Session Module could also include:
```
modules/Session/Infrastructure/Session/
├── NativeSessionStorage.php         (current)
├── RedisSessionStorage.php          (future: Redis-backed)
├── MemcachedSessionStorage.php      (future: Memcached)
├── JwtSessionStorage.php            (future: stateless JWT)
└── FileSessionStorage.php           (future: file-based)
```

---

## Test Expectations

### Before
```
171 tests passing
Core services.json references: src/Infrastructure/Persistence/InFileLogRepository, src/Infrastructure/Session/NativeSessionStorage
```

### After
```
171 tests passing (same)
Core services.json: NO references
modules/Logging/Config/services.json: defines LogRepositoryInterface
modules/Session/Config/services.json: defines SessionStorageInterface
Autoload: ~3975 classes (3 more files)
```

---

## Implementation Complexity

| Phase | Complexity | Risk | Time |
|-------|-----------|------|------|
| Phase 1: Create modules/Logging | LOW | LOW | 15 min |
| Phase 2: Create modules/Session | LOW | LOW | 15 min |
| Phase 3: Update core services.json | LOW | LOW | 5 min |
| Phase 4: Move tests | LOW | LOW | 10 min |
| Phase 5: Validate all tests | LOW | LOW | 5 min |

**Total Effort:** ~50 minutes
**Risk:** Very Low (core unchanged, just config changes)
**Test Coverage:** HIGH (existing tests apply to new locations)

---

## FINAL RECOMMENDATION

✅ **Implement OPTION B - Extract to modules/Logging and modules/Session**

### Benefits:
1. ✅ Core stays pure (only orchestration)
2. ✅ Logging is self-contained module
3. ✅ Session is self-contained module
4. ✅ Easy to replace with alternatives (Redis, DB, etc.)
5. ✅ Clear separation of concerns
6. ✅ Extensible for future implementations
7. ✅ All tests continue to pass
8. ✅ Framework stays modular and scalable

### Next Steps:
Execute Phase 1-5 with test validation at each step.

---

**Analysis Complete. Ready for Implementation.**
