# QA Review — Backend: Tutorial
Fecha: 2026-06-18
Scope: Módulo completo `tutorials` — migración, modelo, contrato, repositorio, service, requests, resource, controller, rutas, bindings y permisos.

## Resumen ejecutivo
- Críticos: 0
- Mayores: 1
- Menores: 0
- Estado: CON OBSERVACIONES

---

## Problemas críticos (bloquean merge)

Ninguno.

---

## Problemas mayores

### [M-01 variante] — `index()` no verifica el permiso `tutorials.read`

**Archivo**: `back/app/Http/Controllers/V1/TutorialController.php` línea 19 (versión original)

El método `index()` no tenía verificación de permiso. El permiso `tutorials.read` existe en el PermissionSeeder pero nunca se consultaba: cualquier usuario autenticado podía listar todos los tutoriales sin importar si tenía el permiso asignado.

**Código original**:
```php
public function index(): JsonResponse
{
    try {
        $tutorials = $this->tutorialService->list();

        return $this->makeSuccess(TutorialResource::collection($tutorials));
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Corrección aplicada**:
```php
public function index(Request $request): JsonResponse
{
    try {
        if (! $request->user()->can('tutorials.read')) {
            return $this->makeError(null, 'No tenés permiso para ver los tutoriales.', 403);
        }

        $tutorials = $this->tutorialService->list();

        return $this->makeSuccess(TutorialResource::collection($tutorials));
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Estado**: CORREGIDO en el archivo.

---

## Problemas menores

Ninguno.

---

## Verificaciones cruzadas

- [x] **Resource vs Controller**: OK — `TutorialResource` expone `guid`, `title`, `description`, `source`, `code`, `order`, `created_at`. El controller usa `TutorialResource` en todos los métodos que retornan una entidad. No hay campos expuestos fuera del resource.
- [x] **Form Request vs Migración**: OK — Los campos `title`, `description`, `source`, `code`, `order` validados en `StoreTutorialRequest` y `UpdateTutorialRequest` existen en la tabla `tutorials`. El `enum('source', ['youtube', 'vimeo'])` coincide con `Rule::in(['youtube', 'vimeo'])` en ambos requests.
- [x] **Binding en AppServiceProvider**: OK — Línea 72: `$this->app->bind(TutorialRepositoryInterface::class, TutorialRepositoryEloquent::class)`.
- [x] **Rutas incluidas en api.php**: OK — `routes/api.php` usa `glob(__DIR__ . '/api/*.php')` para incluir automáticamente todos los archivos del directorio, incluyendo `tutorials.php`.
- [x] **Permisos en PermissionSeeder**: OK — Los cuatro permisos del módulo están presentes (líneas 53-56): `tutorials.read`, `tutorials.create`, `tutorials.update`, `tutorials.delete`. El guard es `web`, correcto.

---

## Notas adicionales sobre el código revisado

**Migración**: Correcta. `guid` como `string(36)->unique()`, sin `softDeletes()`, sin foreign keys que requieran `cascadeOnDelete()`.

**Modelo**: `HasGuid` trait presente (genera UUID en `creating` y define `getRouteKeyName()`). `$hidden = ['id']` correcto. `$fillable` no incluye `guid` ni timestamps. Cumple M-02, M-03, m-05.

**Interface**: Contrato bien definido con tipos explícitos. Métodos `update` y `destroy` reciben `Model` (compatible con el BaseRepositoryEloquent).

**Repositorio**: Extiende `BaseRepositoryEloquent`, implementa la interface. `listOrdered()` usa `orderBy('order')`. Sin queries directas al modelo desde el exterior.

**Service**: Inyecta la interface (no la implementación concreta). Sin lógica de query directa al modelo. Cumple M-09.

**Requests**: Ambos tienen `authorize(): bool { return true }` (correcto para esta etapa), método `rules()` completo y `messages()` en castellano. Cumple M-04.

**Resource**: No expone `$this->id`. No usa `$this->resource->toArray()`. Cumple C-01 y M-05.

**Rutas**: Todas bajo `middleware('auth:sanctum')`. Cumple C-04. Parámetros de ruta usan `{guid}`. Cumple m-04 indirectamente (el controller recibe `string $guid`).

---

## Archivos revisados

- `back/database/migrations/2026_06_18_000001_create_tutorials_table.php`
- `back/app/Models/Tutorial.php`
- `back/app/Contracts/Repositories/TutorialRepositoryInterface.php`
- `back/app/Repositories/TutorialRepositoryEloquent.php`
- `back/app/Services/TutorialService.php`
- `back/app/Http/Requests/Tutorials/StoreTutorialRequest.php`
- `back/app/Http/Requests/Tutorials/UpdateTutorialRequest.php`
- `back/app/Http/Resources/V1/TutorialResource.php`
- `back/app/Http/Controllers/V1/TutorialController.php`
- `back/routes/api/tutorials.php`
- `back/app/Providers/AppServiceProvider.php` (verificación de binding)
- `back/database/seeders/PermissionSeeder.php` (verificación de permisos)
- `back/routes/api.php` (verificación de inclusión de rutas)
- `back/app/Traits/HasGuid.php` (verificación de convención M-02/M-03)
- `back/app/Repositories/BaseRepositoryEloquent.php` (verificación de contrato base)
- `back/app/Http/Controllers/V1/SupportMessageController.php` (referencia de patrón)
- `back/app/Services/SupportMessageService.php` (referencia de patrón)
