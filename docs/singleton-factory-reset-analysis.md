# Análisis: Reset de Singleton en ContainerFactory

> **Superseded analysis:** The current `ContainerFactory` is not a singleton and has no reset API. See [service definitions](service-definitions.md) and [historical documentation](historical-documentation.md).

## Problema Identificado

Durante la revisión del PR, se identificó que usar **reflexión** para resetear singletons en tests indica un problema de diseño:

```php
// Código problemático original
$reflection = new \ReflectionClass(ContainerFactory::class);
$property = $reflection->getProperty('instance');
$property->setAccessible(true);
$property->setValue(null, null);
```

## Análisis del Problema

### 1. ¿Por qué se necesitaba el reset?

El `ContainerFactory` implementa el patrón Singleton, manteniendo una única instancia del contenedor en `self::$instance`. En los tests, se necesitaba resetear esta instancia porque:

- El contenedor se comparte entre diferentes tests
- Los tests modifican el contenedor (ej: inyectando `DummyCache`)
- Estas modificaciones persistían y afectaban a otros tests

### 2. Evidencia del problema

Al ejecutar los tests **sin** el reset:

```bash
# Test individual pasa correctamente
$ phpunit tests/Infrastructure/DependencyInjection/ContainerTest.php
OK (7 tests, 32 assertions)

# Suite completa FALLA debido a estado compartido
$ phpunit
ERRORS!
Tests: 176, Assertions: 327, Errors: 1, Failures: 1.
```

El error indicaba que el `DummyCache` inyectado en `ContainerTest` afectaba a otros tests que esperaban el cache real.

### 3. ¿Por qué no es suficiente inyectar DummyCache?

Aunque inyectar `DummyCache` resuelve problemas de interferencia de cache **dentro** del test, no previene que:

1. El singleton mantenga el estado entre tests
2. Las modificaciones al contenedor persistan
3. Otros tests reciban un contenedor "contaminado"

## Solución Implementada

Se agregó un método público `reset()` al `ContainerFactory`:

```php
/**
 * Reset the singleton instance.
 * This method is intended for testing purposes only.
 */
public static function reset(): void
{
    self::$instance = null;
}
```

### Ventajas de esta solución:

1. ✅ **Elimina la reflexión**: No se accede a propiedades privadas
2. ✅ **API clara**: Intención explícita de resetear el singleton
3. ✅ **Testeable**: Los tests son independientes y no comparten estado
4. ✅ **Segura**: Método controlado vs. acceso directo a propiedades privadas
5. ✅ **Documentada**: Claramente marcada como para propósitos de testing

### Uso en tests:

```php
public function setUp(): void
{
    // Reset the singleton to ensure fresh instance
    ContainerFactory::reset();

    $this->container = ContainerFactory::getInstance('./src/Config/services.json');

    // Replace the cache with DummyCache to avoid cache interference
    $this->container->set(CacheInterface::class, new DummyCache());
}
```

## Alternativas Consideradas y Descartadas

### Opción A: Eliminar completamente el reset
❌ **Descartada**: Los tests fallan debido a estado compartido entre tests.

### Opción B: Eliminar el patrón Singleton
❌ **No implementada en este momento**: Requeriría refactorización mayor de la aplicación. Consideración futura para mejorar la arquitectura.

### Opción C: Usar solo DummyCache sin reset
❌ **Insuficiente**: No previene la contaminación del estado del contenedor entre tests.

## Conclusión

**El método `reset()` es necesario** porque:

1. El patrón Singleton causa estado compartido entre tests
2. Los tests modifican el contenedor de maneras que persisten
3. Sin reset, los tests no son independientes ni repetibles

La solución implementada es pragmática y mejora significativamente la calidad del código al:
- Eliminar el uso de reflexión
- Proveer una API clara y documentada
- Mantener la independencia entre tests

## Recomendación Futura

Para mejorar aún más la testabilidad, considerar:

1. **Eliminar el patrón Singleton** en favor de inyección de dependencias pura
2. **Usar un ContainerBuilder** separado del contenedor en sí
3. **Implementar un TestContainerFactory** específico para tests

Esto requeriría refactorización de:
- `public/index.php` (punto de entrada de la aplicación)
- Todos los lugares donde se llama `ContainerFactory::getInstance()`
- Arquitectura general de la aplicación

## Verificación

```bash
# Todos los tests pasan con la nueva implementación
$ podman exec flexi vendor/bin/phpunit
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

OK (176 tests, 349 assertions)
```

**Fecha**: 19 de octubre de 2025
**Branch**: `architecture-improvements`
**Estado**: ✅ Implementado y verificado
