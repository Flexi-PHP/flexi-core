# Sistema de Gestión Modular con Composer

> **Legacy design note:** This document contains historical package names, dependency rules, and examples. The canonical module metadata is defined in [Module Manifest](module-manifest.md); use that specification for new modules. This document has not yet been fully migrated.

## Resumen

Este documento describe la implementación del sistema de gestión modular para Flexi Framework, donde cada módulo es un paquete Composer independiente con sus propias dependencias, permitiendo instalación/desinstalación dinámica y gestión centralizada de versiones.

## Arquitectura

### Estructura General

```
flexi/
├── composer.json (Principal - gestiona todos los módulos)
├── contracts/ (Paquete de contratos compartido)
│   └── composer.json
├── src/ (Core del framework)
└── modules/ (Módulos independientes)
    ├── Auth/
    │   ├── composer.json
    │   ├── Domain/
    │   ├── Infrastructure/
    │   └── Config/
    ├── Cache/
    │   └── composer.json
    └── ...
```

### Principios de Diseño

1. **Arquitectura Hexagonal + Modular**: Cada módulo sigue los principios de arquitectura hexagonal
2. **Dependencia Única en Contracts**: Tanto el core como los módulos dependen únicamente del paquete `cubadevops/flexi-contracts`
3. **Gestión Centralizada**: El `composer.json` principal gestiona todas las dependencias
4. **Symlinks**: Se usan repositorios tipo `path` con symlinks para desarrollo local
5. **Versionado Semántico**: Cada módulo tiene su propia versión independiente

## Módulos Implementados

| Módulo | Paquete | Dependencias Específicas |
|--------|---------|--------------------------|
| Auth | `cubadevops/flexi-module-auth` | `firebase/php-jwt`, PSR HTTP interfaces |
| Cache | `cubadevops/flexi-module-cache` | `psr/simple-cache` |
| DevTools | `cubadevops/flexi-module-devtools` | Ninguna adicional |
| ErrorHandling | `cubadevops/flexi-module-errorhandling` | Ninguna adicional |
| HealthCheck | `cubadevops/flexi-module-healthcheck` | Ninguna adicional |
| Home | `cubadevops/flexi-module-home` | Ninguna adicional |
| Logging | `cubadevops/flexi-module-logging` | `psr/log` |
| Session | `cubadevops/flexi-module-session` | Ninguna adicional |
| Ui | `cubadevops/flexi-module-ui` | Ninguna adicional |
| WebHooks | `cubadevops/flexi-module-webhooks` | Ninguna adicional |

## Estructura de composer.json de Módulo

Cada módulo tiene su propio `composer.json` con la siguiente estructura:

```json
{
  "name": "cubadevops/flexi-module-{nombre}",
  "description": "...",
  "version": "1.0.0",
  "type": "flexi-module",
  "license": "MIT",
  "require": {
    "php": ">=7.4",
    "cubadevops/flexi-contracts": "@dev",
    "dependencia-especifica": "^version"
  },
  "autoload": {
    "psr-4": {
      "Flexi\\Modules\\{Nombre}\\": ""
    }
  },
  "extra": {
    "flexi": {
      "module-name": "{Nombre}",
      "module-type": "infrastructure|application",
      "config-files": [
        "Config/services.json",
        "Config/routes.json"
      ],
      "provides": [
        "feature-1",
        "feature-2"
      ]
    }
  }
}
```

### Campos Personalizados (`extra.flexi`)

- **module-name**: Nombre del módulo
- **module-type**: Tipo de módulo (`infrastructure`, `application`, `domain`)
- **config-files**: Archivos de configuración que proporciona el módulo
- **provides**: Lista de características/servicios que proporciona

## Gestión de Módulos

### Instalación de un Módulo

Para instalar un módulo específico:

```bash
composer require cubadevops/flexi-module-auth:@dev
```

Composer automáticamente:
1. Crea un symlink desde `vendor/cubadevops/flexi-module-auth` → `modules/Auth`
2. Instala las dependencias específicas del módulo
3. Resuelve conflictos de versiones con otros módulos
4. Actualiza el autoloader

### Desinstalación de un Módulo

Para desinstalar un módulo:

```bash
composer remove cubadevops/flexi-module-auth
```

Esto:
1. Elimina el symlink del vendor
2. Remueve las dependencias exclusivas del módulo
3. Actualiza el autoloader

### Actualización de Módulos

Actualizar todos los módulos:

```bash
composer update "cubadevops/flexi-module-*"
```

Actualizar un módulo específico:

```bash
composer update cubadevops/flexi-module-auth
```

### Instalación Completa (todos los módulos)

El `composer.json` principal ya incluye todos los módulos en `require`:

```bash
composer install
```

## Gestión de Versiones y Compatibilidad

### Versionado Semántico

Cada módulo sigue [Semantic Versioning](https://semver.org/):

- **MAJOR** (1.0.0): Cambios incompatibles en la API
- **MINOR** (1.1.0): Nueva funcionalidad compatible hacia atrás
- **PATCH** (1.0.1): Correcciones de bugs compatibles

### Compatibilidad entre Módulos

Las dependencias PSR utilizan rangos flexibles para máxima compatibilidad:

```json
"psr/log": "^1.1|^2.0|^3.0"
```

Esto permite que diferentes módulos usen versiones diferentes de PSR interfaces sin conflictos.

### Dependencia en Contracts

Todos los módulos dependen de `cubadevops/flexi-contracts`:

```json
"cubadevops/flexi-contracts": "@dev"
```

El versionado de contracts debe ser cuidadoso:
- Cambios breaking en contracts afectan a TODOS los módulos
- Se recomienda usar versionado explícito en producción: `^1.0`

## Configuración del composer.json Principal

### Repositories

El compositor principal define repositories tipo `path` para cada módulo:

```json
"repositories": [
  {
    "type": "path",
    "url": "./contracts",
    "options": {
      "symlink": true
    }
  },
  {
    "type": "path",
    "url": "./modules/Auth",
    "options": {
      "symlink": true
    }
  }
]
```

### Require

Todos los módulos están en la sección `require`:

```json
"require": {
  "cubadevops/flexi-module-auth": "@dev",
  "cubadevops/flexi-module-cache": "@dev",
  ...
}
```

## Desarrollo Local vs Producción

### Desarrollo Local

En desarrollo, se usan symlinks y versión `@dev`:

```json
"cubadevops/flexi-module-auth": "@dev"
```

Los cambios en `modules/Auth` se reflejan inmediatamente sin reinstalar.

### Producción

Para producción, se recomienda:

1. **Publicar módulos en repositorios Git separados**
2. **Usar tags de versión**: `"cubadevops/flexi-module-auth": "^1.0"`
3. **Cambiar repositories a tipo `vcs`**:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/cubadevops/flexi-module-auth"
  }
]
```

## Scripts de Composer

Se pueden agregar scripts para gestionar módulos:

```json
"scripts": {
  "module:list": "composer show 'cubadevops/flexi-module-*'",
  "module:update": "composer update 'cubadevops/flexi-module-*'",
  "module:validate": "@php bin/console modules:validate",
  "post-autoload-dump": [
    "@php bin/console cache:clear"
  ]
}
```

## Herramienta CLI para Módulos

Se puede crear un comando de consola para gestionar módulos:

```bash
# Listar módulos instalados
php bin/console modules:list

# Información de un módulo
php bin/console modules:info auth

# Habilitar/Deshabilitar módulo (sin desinstalar)
php bin/console modules:enable auth
php bin/console modules:disable auth

# Validar configuración de módulos
php bin/console modules:validate
```

## Ejemplo de Flujo Completo

### Crear un Nuevo Módulo

1. **Crear estructura de directorios**:
```bash
mkdir -p modules/MyModule/{Domain,Infrastructure,Config}
```

2. **Crear composer.json**:
```json
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
```

3. **Agregar al composer.json principal**:
```json
"require": {
  "cubadevops/flexi-module-mymodule": "@dev"
},
"repositories": [
  {
    "type": "path",
    "url": "./modules/MyModule",
    "options": {
      "symlink": true
    }
  }
]
```

4. **Instalar**:
```bash
composer update cubadevops/flexi-module-mymodule
```

### Desinstalar un Módulo

```bash
# 1. Remover del composer.json principal (manualmente o con composer)
composer remove cubadevops/flexi-module-mymodule

# 2. Opcional: eliminar el directorio físico
rm -rf modules/MyModule
```

## Ventajas del Sistema

1. **Modularidad Real**: Cada módulo es completamente independiente
2. **Gestión de Dependencias**: Composer maneja automáticamente conflictos
3. **Versionado Granular**: Cada módulo puede evolucionar independientemente
4. **Instalación Selectiva**: Instala solo los módulos necesarios
5. **Desarrollo Simplificado**: Symlinks permiten desarrollo sin reinstalaciones
6. **Reutilización**: Módulos pueden ser usados en otros proyectos
7. **Testing Aislado**: Cada módulo puede tener sus propios tests

## Desventajas y Consideraciones

1. **Complejidad Inicial**: Más archivos composer.json para mantener
2. **Gestión de Versiones**: Requiere disciplina en versionado semántico
3. **Breaking Changes en Contracts**: Afectan a todos los módulos
4. **Overhead de Symlinks**: En algunos sistemas de archivos puede haber problemas
5. **Tamaño del Vendor**: Dependencias duplicadas si no hay buen manejo de rangos

## Mejores Prácticas

1. **Usa rangos flexibles para PSR**: `"psr/log": "^1.1|^2.0|^3.0"`
2. **Versiona contracts cuidadosamente**: Cambios breaking son costosos
3. **Documenta dependencias**: En el README de cada módulo
4. **Tests de integración**: Verifica compatibilidad entre módulos
5. **CI/CD por módulo**: Cada módulo puede tener su propio pipeline
6. **Changelog por módulo**: Mantén un CHANGELOG.md en cada módulo
7. **Minimiza dependencias externas**: Usa solo lo necesario

## Migración de Sistema Actual

El sistema ya está configurado. Para aplicar los cambios:

```bash
# 1. Eliminar autoload antiguo de modules/ (ya hecho)
# 2. Regenerar autoloader
composer dump-autoload

# 3. Actualizar dependencias
composer update

# 4. Verificar que todo funciona
composer test
```

## Conclusión

Este sistema proporciona una arquitectura modular robusta y escalable para Flexi Framework, permitiendo:

- ✅ Módulos completamente independientes
- ✅ Gestión centralizada de dependencias
- ✅ Versionado granular por módulo
- ✅ Instalación/desinstalación dinámica
- ✅ Compatibilidad entre versiones garantizada por Composer
- ✅ Desarrollo local eficiente con symlinks
- ✅ Lista para producción con repositorios Git separados

El framework mantiene su arquitectura hexagonal mientras gana flexibilidad y modularidad a nivel de gestión de paquetes.
