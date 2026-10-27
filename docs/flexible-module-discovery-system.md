# Nuevo Sistema de Descubrimiento de Módulos

> **Legacy design note:** This document describes the historical `extra.flexi-module` format. New modules must use the canonical `extra.flexi` schema in [Module Manifest](module-manifest.md). The legacy format is retained only as migration context.

## Resumen de Cambios

Se ha implementado un sistema de descubrimiento de módulos más flexible basado en metadatos del `composer.json` que elimina la dependencia de prefijos específicos como "cubadevops/".

## Características del Nuevo Sistema

### 1. Detección basada en metadatos
Los módulos ahora se identifican mediante el campo `extra.flexi-module` en su `composer.json`:

```json
{
  "name": "cualquier-vendor/mi-modulo-auth",
  "description": "Mi módulo de autenticación personalizado",
  "version": "1.0.0",
  "type": "library",
  "extra": {
    "flexi-module": {
      "name": "Auth",
      "description": "Módulo de autenticación avanzada",
      "version": "1.0.0",
      "author": "Mi Empresa"
    }
  }
}
```

### 2. Flexibilidad de vendors
Ahora cualquier vendor puede crear módulos:
- `miempresa/modulo-usuarios`
- `otrodev/sistema-pagos`
- `cubadevops/flexi-module-auth` (mantiene compatibilidad)

### 3. Sistema de cache inteligente
- Cache automático basado en `composer.lock`
- Se invalida automáticamente cuando se instalan/desinstalan paquetes
- Mejora significativa de rendimiento

### 4. Detección automática de nombres
Si no se especifica `name` en `extra.flexi-module`, se deriva automáticamente:
- `vendor/auth-module` → `Auth`
- `cubadevops/flexi-module-user` → `User`
- `miempresa/sistema-pagos` → `SistemaPagos`

## Componentes Actualizados

1. **ModuleCacheManager**: Gestión inteligente de cache
2. **VendorModuleDetector**: Detección flexible basada en metadatos
3. **HybridModuleDetector**: Combina local y vendor con cache
4. **InstalledModulesFilter**: Sistema de filtrado actualizado sin patrones fijos

## Ejemplo de Uso

```php
// Crear el cache manager
$cacheManager = new ModuleCacheManager('./var');

// Crear detectores
$vendorDetector = new VendorModuleDetector($cacheManager);
$hybridDetector = new HybridModuleDetector($cacheManager);

// El filtro ahora es flexible
$filter = new InstalledModulesFilter($stateManager, $hybridDetector);

// Detecta módulos de cualquier vendor automáticamente
$modules = $hybridDetector->getAllModules();
```

## Filosofía de Gestión de Estado

### Control Explícito vs Automático

El nuevo sistema adopta una filosofía de **control explícito** sobre el estado de los módulos:

**❌ Eliminado: Sincronización Automática**
- El comando `modules:sync` ha sido ELIMINADO
- No hay activación automática basada en presencia física
- Previene activaciones no deseadas o accidentales

**✅ Nuevo Modelo: Activación Explícita**
- Los módulos se detectan automáticamente
- La activación requiere decisión consciente del administrador
- Estado persiste independiente del filesystem

### Flujo de Gestión Recomendado

```bash
# 1. Instalar módulo via Composer
composer require vendor/mi-modulo

# 2. Verificar módulos disponibles
php bin/console modules:status

# 3. Activar módulo específico (decisión explícita)
php bin/console modules:activate MiModulo

# 4. Verificar estado
php bin/console modules:status MiModulo --details
```

## Comandos Disponibles

### Comandos Principales
- `modules:list` - Listar módulos disponibles
- `modules:activate <module>` - Activar módulo específico
- `modules:deactivate <module>` - Desactivar módulo específico
- `modules:status [module]` - Estado de módulos
- `modules:info <module>` - Información detallada

### Comandos de Mantenimiento
- `modules:validate` - Validar configuraciones
- ~~`modules:sync`~~ - **ELIMINADO** (ver [decisión arquitectural](./modules-sync-removal.md))

## Migración

Los módulos existentes seguirán funcionando. Para nuevos módulos:

1. Agregar `extra.flexi-module` al `composer.json`
2. El sistema detecta automáticamente módulos de cualquier vendor
3. Cache automático mejora el rendimiento

## Próximos Pasos

- Actualizar servicios en `services.json`
- Registrar nuevos comandos CLI
- Crear tests para el nuevo sistema
- Documentar estándar de metadatos para desarrolladores
