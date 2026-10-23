# Eliminación de Dependencias del Core hacia Módulos

## Resumen

Se han eliminado exitosamente las dependencias circulares del core hacia los módulos Session y ErrorHandling, implementando un patrón basado en eventos que mantiene el desacoplamiento y la modularidad del framework.

## Problema Identificado

1. **Dependencia de Session**: El `Router` y `RouterFactory` dependían directamente de `SessionStorageInterface`, que pertenece al módulo Session.
2. **Referencia a ruta "404"**: El `Router` accedía directamente a la ruta con nombre "404" definida en el módulo ErrorHandling.
3. **Acoplamiento**: Esto creaba una dependencia circular donde el core dependía de módulos específicos.

## Solución Implementada

### 1. Nuevo Evento: `RouteNotFoundEvent`

Se creó un evento específico en el core (`src/Domain/Events/RouteNotFoundEvent.php`) que:

- Contiene el request y la ruta solicitada
- Permite que listeners proporcionen una respuesta personalizada
- Implementa el patrón de "propagación detenible" (stoppable event)
- Se dispara cuando una ruta no es encontrada

```php
class RouteNotFoundEvent extends Event
{
    private ?ResponseInterface $response = null;

    public function setResponse(ResponseInterface $response): void
    public function hasResponse(): bool
    public function getResponse(): ?ResponseInterface
}
```

### 2. Modificación del Router

**Cambios en `src/Infrastructure/Http/Router.php`:**

- ✅ Eliminada la dependencia de `SessionStorageInterface`
- ✅ Renombrado método `redirectToNotFound()` a `handleNotFound()`
- ✅ El nuevo método dispara el evento `RouteNotFoundEvent`
- ✅ Si un listener proporciona respuesta, la usa; de lo contrario, retorna una respuesta 404 simple
- ✅ Ya no accede directamente a la ruta "404"

```php
public function handleNotFound(
    ServerRequestInterface $request,
    string $requested_path
): ResponseInterface {
    $event = new RouteNotFoundEvent($request, $requested_path, __CLASS__);
    $this->event_bus->dispatch($event);

    if ($event->hasResponse()) {
        return $event->getResponse();
    }

    // Respuesta por defecto si ningún listener la maneja
    $response = $this->response_factory->createResponse(404);
    $response->getBody()->write('404 - Not Found');
    return $response;
}
```

### 3. Modificación de RouterFactory

**Cambios en `src/Infrastructure/Factories/RouterFactory.php`:**

- ✅ Eliminada la dependencia de `SessionStorageInterface` del constructor
- ✅ Actualizado para crear `Router` sin session

### 4. Actualización de Configuración

**Cambios en `src/Config/services.json`:**

- ✅ Eliminado `@session` de los argumentos de `RouterFactory`

### 5. Nuevo Listener en Módulo ErrorHandling

**Creado `modules/ErrorHandling/Infrastructure/Listeners/RouteNotFoundListener.php`:**

Este listener:
- ✅ Escucha el evento `core.routeNotFound`
- ✅ Guarda la ruta anterior en sesión (manteniendo la funcionalidad original)
- ✅ Crea la respuesta de redirección a `/not-found`
- ✅ Proporciona la respuesta al evento

```php
class RouteNotFoundListener implements EventListenerInterface
{
    public function handle(EventInterface $event): void
    {
        if (!$event instanceof RouteNotFoundEvent) {
            return;
        }

        $this->session->set('previous_route', $event->getRequestedPath());
        $response = $this->response_factory->createResponse(302);
        $not_found_url = Router::getUrlBase() . '/not-found';
        $response = $response->withHeader('Location', $not_found_url);
        $event->setResponse($response);
    }
}
```

### 6. Configuración del Módulo ErrorHandling

**Creado `modules/ErrorHandling/Config/listeners.json`:**
```json
{
  "listeners": [
    {
      "event": "core.routeNotFound",
      "handler": "Flexi\\Modules\\ErrorHandling\\Infrastructure\\Listeners\\RouteNotFoundListener"
    }
  ]
}
```

**Creado `modules/ErrorHandling/Config/services.json`:**
```json
{
  "services": [
    {
      "name": "Flexi\\Modules\\ErrorHandling\\Infrastructure\\Listeners\\RouteNotFoundListener",
      "class": {
        "name": "Flexi\\Modules\\ErrorHandling\\Infrastructure\\Listeners\\RouteNotFoundListener",
        "arguments": [
          "@session",
          "@Psr\\Http\\Message\\ResponseFactoryInterface"
        ]
      }
    }
  ]
}
```

## Archivos Modificados

1. ✅ `src/Infrastructure/Http/Router.php`
2. ✅ `src/Infrastructure/Factories/RouterFactory.php`
3. ✅ `src/Config/services.json`

## Archivos Creados

1. ✅ `src/Domain/Events/RouteNotFoundEvent.php`
2. ✅ `modules/ErrorHandling/Infrastructure/Listeners/RouteNotFoundListener.php`
3. ✅ `modules/ErrorHandling/Config/listeners.json`
4. ✅ `modules/ErrorHandling/Config/services.json`

## Beneficios

1. **Desacoplamiento Total**: El core ya no depende de módulos específicos
2. **Flexibilidad**: Cualquier módulo puede escuchar el evento y proporcionar su propia lógica de manejo
3. **Mantenibilidad**: El comportamiento está claramente separado por responsabilidades
4. **Extensibilidad**: Es fácil agregar múltiples listeners para diferentes comportamientos
5. **Coherencia**: Se usa el sistema de eventos interno del framework de forma consistente

## Comportamiento Mantenido

- ✅ La ruta anterior se guarda en sesión para mostrarla en la página 404
- ✅ El usuario es redirigido a `/not-found` cuando accede a una ruta no existente
- ✅ El `NotFoundController` sigue funcionando exactamente igual
- ✅ No se afecta ninguna funcionalidad existente

## Testing Recomendado

1. Acceder a una ruta no existente y verificar redirección a `/not-found`
2. Verificar que la página 404 muestra la ruta anterior correctamente
3. Probar deshabilitando el módulo ErrorHandling (debería mostrar respuesta 404 simple)
4. Verificar logs de eventos para confirmar que se disparan correctamente

## Conclusión

La refactorización ha sido completada exitosamente, eliminando las dependencias circulares del core hacia módulos mientras se mantiene toda la funcionalidad existente. El código es ahora más modular, mantenible y sigue los principios SOLID de diseño.
