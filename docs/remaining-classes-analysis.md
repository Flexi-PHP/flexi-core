# Analysis of Remaining Classes in Domain/Classes

> **Superseded analysis:** The `Domain/Classes` layout described here is outdated. Use the current [HTTP routing](http-routing.md) and [service definitions](service-definitions.md) documentation instead.

## Analyzed Classes

### 1. **Route.php** ❌ NOT Domain
**Current location:** `Domain/Classes/Route.php`
**Recommendation:** `Infrastructure/Http/Route.php`

**Justification:**
- Handles HTTP concepts (GET, POST, PUT, etc.)
- Is a transport infrastructure concern
- Works with PSR's `RequestHandlerInterface`
- Is specific to web/HTTP implementation

---

### 2. **Service.php, ServiceClassDefinition.php, ServiceFactoryDefinition.php** ❌ NOT Domain
**Current location:** `Domain/Classes/`
**Recommendation:** `Infrastructure/DependencyInjection/`

**Justification:**
- Part of the Dependency Injection system
- Configure how services are built (Factory, Class)
- Is an infrastructure concern, not business logic
- DI container is already in `Infrastructure/DependencyInjection/`

---

### 3. **Log.php** ✅ Can stay in Domain
**Current location:** `Domain/Classes/Log.php`
**Recommendation:** `Domain/Entities/Log.php` or `Domain/ValueObjects/Log.php`

**Justification:**
- Represents a domain concept (a log entry)
- Does NOT do I/O (only structures data)
- Implements domain's `LogInterface`
- Is a Value Object or Entity depending on whether it has identity

**Alternative:** If log is considered pure infrastructure, move to `Infrastructure/Logging/`

---

### 4. **PlainTextMessage.php** ⚠️ Borderline (can be in Domain or Application)
**Current location:** `Domain/Classes/PlainTextMessage.php`
**Recommendation:** `Domain/ValueObjects/PlainTextMessage.php` or `Application/DTO/PlainTextMessage.php`

**Justification:**
- Is a Value Object representing a text message
- Implements domain's `MessageInterface`
- Is immutable (only created with data)
- Does NOT have complex business logic
- Is used as use case response

**Decision:** Keep in Domain but in correct folder (ValueObjects)

---

### 5. **Collection.php, ObjectCollection.php** ✅ Can stay in Domain
**Current location:** `Domain/Classes/`
**Recommendation:** `Domain/Collections/Collection.php` and `Domain/Collections/ObjectCollection.php`

**Justification:**
- Are reusable domain data structures
- Have no I/O or infrastructure dependencies
- Implement domain's `CollectionInterface`
- Are generic concepts that can contain entities/value objects

---

### 6. **DummySearchCriteria.php** ✅ Domain
**Current location:** `Domain/Classes/`
**Recommendation:** `Domain/Criteria/DummySearchCriteria.php`

**Justification:**
- Implements domain's `CriteriaInterface`
- Is a domain pattern (Specification Pattern)
- Represents domain search criteria

---

## Summary of Recommended Movements

### To Move to Infrastructure:
```
Domain/Classes/Route.php → Infrastructure/Http/Route.php
Domain/Classes/Service.php → Infrastructure/DependencyInjection/Service.php
Domain/Classes/ServiceClassDefinition.php → Infrastructure/DependencyInjection/ServiceClassDefinition.php
Domain/Classes/ServiceFactoryDefinition.php → Infrastructure/DependencyInjection/ServiceFactoryDefinition.php
```

### To Reorganize within Domain:
```
Domain/Classes/Log.php → Domain/Entities/Log.php (or Domain/ValueObjects/)
Domain/Classes/PlainTextMessage.php → Domain/ValueObjects/PlainTextMessage.php
Domain/Classes/Collection.php → Domain/Collections/Collection.php
Domain/Classes/ObjectCollection.php → Domain/Collections/ObjectCollection.php
Domain/Classes/DummySearchCriteria.php → Domain/Criteria/DummySearchCriteria.php
```

---

## Proposed New Structure

```
src/
├── Domain/
│   ├── Collections/                # ← NEW
│   │   ├── Collection.php
│   │   └── ObjectCollection.php
│   ├── Criteria/                   # ← NEW
│   │   └── DummySearchCriteria.php
│   ├── Entities/
│   │   └── Log.php                 # ← MOVE (if has identity)
│   ├── Events/                     # ✓ Already created
│   ├── ValueObjects/
│   │   ├── PlainTextMessage.php    # ← MOVE
│   │   └── Log.php                 # ← MOVE (if immutable)
│   └── Classes/                    # ← DELETE (empty after movements)
│
└── Infrastructure/
    ├── DependencyInjection/
    │   ├── Container.php
    │   ├── Service.php              # ← MOVE
    │   ├── ServiceClassDefinition.php  # ← MOVE
    │   └── ServiceFactoryDefinition.php # ← MOVE
    └── Http/
        └── Route.php                # ← MOVE
```

---

## Recommended Execution Order

1. **Phase 1: Create new structures**
   - `Domain/Collections/`
   - `Domain/Criteria/`

2. **Phase 2: Move Domain classes to subdirectories**
   - Collection → Domain/Collections
   - PlainTextMessage → Domain/ValueObjects
   - Log → Domain/Entities or Domain/ValueObjects
   - DummySearchCriteria → Domain/Criteria

3. **Phase 3: Move Domain classes to Infrastructure**
   - Route → Infrastructure/Http
   - Service* → Infrastructure/DependencyInjection

4. **Phase 4: Update imports**
   - Find and replace all imports
   - Update tests

5. **Phase 5: Run tests and validate**

6. **Phase 6: Delete old files**

7. **Phase 7: Delete Domain/Classes/ directory (if empty)**

---

## Estimated Impact

- **Files to move:** 9
- **Tests to update:** ~15-20 approximately
- **Configurations to update:** Possibly none (not in JSON)
- **Risk:** Medium (many classes are used in various places)

---

**Final Recommendation:** Proceed with movements in small phases, running tests after each phase.

````
