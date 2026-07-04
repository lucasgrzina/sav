# Plan técnico: Diferenciación de roles de plataforma vs roles de tenant

## Input procesado
Brief informal del usuario (chat directo) — sin archivo de spec/ticket preexistente.

## Resumen ejecutivo
Se agrega el campo `type` (`'platform'` | `'tenant'`) a la tabla `roles` para separar semánticamente los roles de gestión interna de plataforma de los roles operativos de cada tenant veterinario. La restricción de negocio (roles tenant son inmutables en nombre/type, no se pueden crear ni eliminar) se implementa en `RoleService`, no en DB constraints. Los cambios afectan: una migration nueva, `RoleSeeder`, `Role` model, `RoleService`, `RoleRepositoryEloquent`, `RoleResource`, `UpdateRoleRequest`, y los archivos TypeScript del módulo `roles` en frontend. No se crean nuevos endpoints; los existentes absorben el comportamiento diferenciado.

---

## Decisiones tomadas

**DEC-01 — Implementar el type como string simple, no como PHP Enum**
  Decisión: Usar `string` con constantes en el model (`const TYPE_PLATFORM = 'platform'`, `const TYPE_TENANT = 'tenant'`). No usar BackedEnum de PHP.
  Justificación: La tabla de roles es de Spatie y no hay precedente de Enums en el proyecto (ningún enum de DB existe en las migraciones actuales). Agregar un Enum solo para dos valores introduce dependencia innecesaria. Las constantes en el model son suficientes para legibilidad y permiten comparación sin casteo.
  Alternativa descartada: `BackedEnum RoleType` — agrega un archivo extra, requiere cast en el model, y Spatie no conoce el campo de todos modos.

**DEC-02 — Enforcar restricciones en RoleService, no con DB constraints ni Policy**
  Decisión: `RoleService::create()`, `update()` y `destroy()` chequean el type antes de ejecutar la operación. El controller llama al service y maneja la excepción.
  Justificación: La spec lo indica explícitamente. Además, Spatie crea roles internamente (ej. syncPermissions no usa nuestro service), y una DB constraint en nombre/type rompería eso. Una Policy de Laravel sería adecuada para autorización, pero aquí es lógica de negocio pura (no autorización), por lo que el service es el lugar correcto.
  Alternativa descartada: Policy de Laravel — correcto para "quién puede hacer X", incorrecto para "este objeto no admite esta operación por su estado".

**DEC-03 — Lanzar excepción tipada en el Service en lugar de retornar boolean**
  Decisión: Crear `App\Exceptions\RoleImmutableException` que extiende `\RuntimeException`. El controller la captura en el catch de `\Exception` con `makeFromException()`. El `ResponseHelper::makeFromException()` ya maneja el código HTTP 422 para `RuntimeException`.
  Justificación: Mantiene el flujo del controller limpio (sin ifs adicionales), es consistente con cómo el controller ya maneja errores via `makeFromException`, y permite al frontend distinguir el error por mensaje.
  Alternativa descartada: Retornar array `['success' => false, 'message' => '...']` desde el service — rompe el contrato de tipo de retorno y obliga al controller a interpretar el resultado.

**DEC-04 — Verificar en `makeFromException` el mapeo de RoleImmutableException**
  Decisión: `RoleImmutableException` se mapea a HTTP 422. Revisar `ResponseHelper` antes de implementar; si no maneja `RuntimeException` como 422, agregar el mapeo explícito.
  Justificación: 422 es semánticamente correcto ("entidad no procesable por sus restricciones de negocio"). El controller no necesita cambios de estructura.
  Alternativa descartada: HTTP 403 — semánticamente implica falta de permisos del usuario, no restricción del objeto.

**DEC-05 — `UpdateRoleRequest`: hacer `name` opcional (`nullable`) y mover la validación de inmutabilidad al Service**
  Decisión: Cambiar `name` en `UpdateRoleRequest` de `required` a `sometimes|string|max:80|unique`. Así el request acepta payloads sin `name` (para roles tenant donde solo se actualizan permisos). El service descarta `name` si el rol es tenant.
  Justificación: La request no conoce el type del rol (no tiene acceso al model en tiempo de validación sin query extra). La regla de negocio "el nombre de un role tenant no se puede cambiar" es responsabilidad del service. Si `name` fuera `required` en la request, obligaría al frontend a enviar el nombre aunque no cambie, lo que es incorrecto para tenant.
  Alternativa descartada: Validar en el FormRequest con un Closure que lea el model — añade una query extra en la capa de validación, viola separación de responsabilidades.

**DEC-06 — Default del campo `type` en la migration: `'platform'`**
  Decisión: `->default('platform')` en la migration de alter.
  Justificación: Si por algún motivo existe un rol sin type (imposible en flujo normal tras el seeder), es menos dañino clasificarlo como platform (CRUD completo) que como tenant (bloqueado). El seeder setea el type correcto inmediatamente después.
  Alternativa descartada: `->default('tenant')` — peor falla silenciosa; un rol creado sin type quedaría inmutable.

**DEC-07 — No agregar campo `type` al `create` endpoint como input del cliente**
  Decisión: Los roles nuevos creados via API siempre son `platform`. No se expone `type` como campo de `CreateRoleRequest`.
  Justificación: La regla de negocio dice "no se pueden crear nuevos roles tenant". Por lo tanto, cualquier rol creado via API es necesariamente `platform`. Exponer `type` en el payload de creación sería un vector para crear roles tenant desde afuera.
  Alternativa descartada: Permitir que el cliente elija el type en creación — viola la regla de negocio.

**DEC-08 — Frontend: `UpdateRolePayload` queda con `name` opcional**
  Decisión: Cambiar `UpdateRolePayload` para que `name` sea `name?: string`. El componente que renderiza el formulario de edición debe decidir si muestra el campo nombre según `role.type`.
  Justificación: Consistente con DEC-05. El formulario de edición de un rol tenant solo muestra el selector de permisos.
  Alternativa descartada: Mantener `name` required en el tipo TS — generaría errores de tipado si el componente no envía nombre para roles tenant.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Exceptions/RoleImmutableException.php`
**Propósito:** Excepción tipada para comunicar que una operación no está permitida sobre un rol de tipo tenant.
**Firma principal:**
```php
namespace App\Exceptions;

class RoleImmutableException extends \RuntimeException
{
    public function __construct(string $message = 'Los roles de tenant no pueden ser modificados de esta manera.')
    {
        parent::__construct($message);
    }
}
```
**Dependencias inyectadas:** ninguna.

#### `back/database/migrations/2026_06_12_000001_add_type_to_roles_table.php`
**Propósito:** Agregar columna `type` a la tabla `roles` con default `'platform'`.
**Firma principal:**
```php
Schema::table('roles', function (Blueprint $table) {
    $table->string('type')->default('platform')->after('guard_name');
});
```
**Reversión (`down`):**
```php
Schema::table('roles', function (Blueprint $table) {
    $table->dropColumn('type');
});
```
**Nota:** No se agrega índice en `type` — la tabla de roles es pequeña y el filtro por type es de baja frecuencia.

---

### Archivos a modificar

#### `back/app/Models/Role.php`
**Cambio:** Agregar constantes de type, `$fillable`, y dos query scopes.
**Antes (resumido):** Solo guid en `booted()` y `getRouteKeyName()`.
**Después:**
```php
class Role extends SpatieRole
{
    public const TYPE_PLATFORM = 'platform';
    public const TYPE_TENANT   = 'tenant';

    protected $fillable = ['name', 'guard_name', 'type', 'guid'];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->guid)) {
                $model->guid = Str::uuid()->toString();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'guid';
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PLATFORM);
    }

    public function scopeTenant(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_TENANT);
    }

    public function isPlatform(): bool
    {
        return $this->type === self::TYPE_PLATFORM;
    }

    public function isTenant(): bool
    {
        return $this->type === self::TYPE_TENANT;
    }
}
```
**Imports a agregar:** `use Illuminate\Database\Eloquent\Builder;`

**Nota sobre `$fillable`:** `SpatieRole` no declara `$fillable` como `protected` final; la propiedad puede sobreescribirse en el model hijo. Verificar que `name` y `guard_name` sigan siendo asignables (Spatie los necesita) — incluirlos en el array.

---

#### `back/database/seeders/RoleSeeder.php`
**Cambio:** Agregar `type` en cada `firstOrCreate` y ejecutar `updateOrCreate` para setear el type en registros existentes que no lo tengan (idempotencia).

**Patrón a aplicar en cada rol:**

Reemplazar todos los `firstOrCreate` por `updateOrCreate` con los campos de búsqueda en el primer argumento y los valores a setear (incluido `type`) en el segundo. Esto garantiza que si el rol ya existía sin type, se actualiza.

```php
// Roles platform
$superAdmin = Role::updateOrCreate(
    ['name' => 'super-admin', 'guard_name' => 'web'],
    ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
);

$admin = Role::updateOrCreate(
    ['name' => 'admin', 'guard_name' => 'web'],
    ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
);

$operador = Role::updateOrCreate(
    ['name' => 'operador', 'guard_name' => 'web'],
    ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
);
```

**Problema con guid en updateOrCreate:** `updateOrCreate` ejecuta un UPDATE cuando el registro existe, y el segundo argumento incluye `guid`. Eso sobreescribiría el guid existente con uno nuevo en cada seed. Para evitarlo, el guid solo debe setearse al crear. Usar el siguiente patrón:

```php
$superAdmin = Role::firstOrCreate(
    ['name' => 'super-admin', 'guard_name' => 'web'],
    ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
);
// Setear type si el registro ya existía sin él
if ($superAdmin->type === null) {
    $superAdmin->update(['type' => Role::TYPE_PLATFORM]);
}
```

**Alternativa más limpia (recomendada):** después de todos los `firstOrCreate`, ejecutar un update masivo para backfill de type en registros existentes:

```php
// Al final del método run(), backfill de type para registros sin type
$platformNames = ['super-admin', 'admin', 'operador'];
$tenantNames   = ['vet', 'vet-assistant', 'vet-administrative', 'client-owner', 'client-manager', 'client-administrative'];

Role::whereIn('name', $platformNames)->whereNull('type')->update(['type' => Role::TYPE_PLATFORM]);
Role::whereIn('name', $tenantNames)->whereNull('type')->update(['type' => Role::TYPE_TENANT]);
```

Con `default('platform')` en la migration, `whereNull('type')` solo aplica si se corre el seeder contra una DB que ya tenía los roles ANTES de la migration. Para DBs frescas, la columna tiene el default y el backfill es un no-op. Para DBs en producción con roles existentes, el default de la migration ya los setea a `'platform'`, así que solo los tenant necesitan corrección explícita.

**Versión final limpia del seeder:**

```php
public function run(): void
{
    $superAdmin = Role::firstOrCreate(
        ['name' => 'super-admin', 'guard_name' => 'web'],
        ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
    );
    $admin = Role::firstOrCreate(
        ['name' => 'admin', 'guard_name' => 'web'],
        ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
    );
    $operador = Role::firstOrCreate(
        ['name' => 'operador', 'guard_name' => 'web'],
        ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
    );

    // Permisos de plataforma (sin cambios)
    $superAdmin->syncPermissions(Permission::all());
    $admin->syncPermissions(Permission::whereIn('name', [...])->get());
    $operador->syncPermissions(Permission::whereIn('name', ['users.read'])->get());

    $tenantRoles = [
        'vet', 'vet-assistant', 'vet-administrative',
        'client-owner', 'client-manager', 'client-administrative',
    ];
    foreach ($tenantRoles as $roleName) {
        Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
    }

    // Backfill para roles existentes que quedaron con default 'platform' pero son tenant
    Role::whereIn('name', $tenantRoles)
        ->where('type', Role::TYPE_PLATFORM)
        ->update(['type' => Role::TYPE_TENANT]);
}
```

El backfill final corrige el caso en que la migration default haya seteado `'platform'` a todos los roles preexistentes antes de que el seeder se ejecute.

---

#### `back/app/Services/RoleService.php`
**Cambio:** Agregar validaciones de tipo en `create()`, `update()` y `destroy()`.

**Método `create(array $data): Role`** — agregar `type = 'platform'` forzado:
```php
public function create(array $data): Role
{
    // Roles creados via API son siempre platform (DEC-07)
    $data['type'] = Role::TYPE_PLATFORM;

    $role = $this->roleRepository->create($data);

    if (! empty($data['permissions'])) {
        $permissions = $this->permissionRepository->findManyByGuids($data['permissions']);
        $role->syncPermissions($permissions);
    }

    return $role->load('permissions');
}
```

**Método `update(Role $role, array $data): Role`** — restricciones para tenant:
```php
public function update(Role $role, array $data): Role
{
    if ($role->isTenant()) {
        // Para roles tenant: solo se permite actualizar permisos
        if (isset($data['name']) && $data['name'] !== $role->name) {
            throw new RoleImmutableException('El nombre de un rol de tenant no puede modificarse.');
        }
        // Descartar name del array para no pisarlo aunque venga igual
        unset($data['name']);
    }

    if (! empty($data['name']) || $role->isPlatform()) {
        $role = $this->roleRepository->update($role, $data);
    }

    if (array_key_exists('permissions', $data)) {
        $permissions = $this->permissionRepository->findManyByGuids($data['permissions'] ?? []);
        $role->syncPermissions($permissions);
    }

    return $role->load('permissions');
}
```

**Nota sobre la lógica de update:** si `name` no viene en `$data` (rol tenant, solo permisos), no se llama a `roleRepository->update()` para evitar un UPDATE vacío. La lógica exacta:

```php
public function update(Role $role, array $data): Role
{
    if ($role->isTenant()) {
        if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
            throw new RoleImmutableException('El nombre de un rol de tenant no puede modificarse.');
        }
        unset($data['name']); // ignorar name aunque venga igual
    }

    // Solo llamar al repositorio si hay campos de modelo que actualizar
    if (! empty($data['name'])) {
        $role = $this->roleRepository->update($role, $data);
    }

    if (array_key_exists('permissions', $data)) {
        $permissions = $this->permissionRepository->findManyByGuids($data['permissions'] ?? []);
        $role->syncPermissions($permissions);
    }

    return $role->load('permissions');
}
```

**Método `destroy(Role $role): void`** — bloquear eliminación de roles tenant:
```php
public function destroy(Role $role): void
{
    if ($role->isTenant()) {
        throw new RoleImmutableException('Los roles de tenant no pueden eliminarse.');
    }

    $this->roleRepository->destroy($role);
}
```

**Import a agregar:**
```php
use App\Exceptions\RoleImmutableException;
use App\Models\Role;
```

---

#### `back/app/Repositories/RoleRepositoryEloquent.php`
**Cambio:** Actualizar `create()` para incluir `type` en los campos persistidos.

**Antes:**
```php
public function create(array $data): Role
{
    return $this->model->newQuery()->create([
        'name'       => $data['name'],
        'guard_name' => 'web',
    ]);
}
```

**Después:**
```php
public function create(array $data): Role
{
    return $this->model->newQuery()->create([
        'name'       => $data['name'],
        'guard_name' => 'web',
        'type'       => $data['type'] ?? Role::TYPE_PLATFORM,
    ]);
}
```

**Cambio en `update()`:** el método actual solo actualiza `name`. Dado que `type` es inmutable para tenant (controlado en service) y no se cambia para platform, el update del repositorio no necesita tocar `type`. Sin embargo, agregar la clave de forma explícita para claridad:

```php
public function update(Model $model, array $data): Role
{
    $updateData = [];
    if (isset($data['name'])) {
        $updateData['name'] = $data['name'];
    }
    // type nunca se actualiza desde el repositorio
    $model->update($updateData);

    return $model->fresh('permissions');
}
```

---

#### `back/app/Http/Resources/V1/RoleResource.php`
**Cambio:** Agregar campo `type` al array retornado.

**Antes:**
```php
return [
    'guid'        => $this->guid,
    'name'        => $this->name,
    'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
    'users_count' => $this->whenCounted('users'),
    'created_at'  => $this->created_at?->toISOString(),
];
```

**Después:**
```php
return [
    'guid'        => $this->guid,
    'name'        => $this->name,
    'type'        => $this->type,
    'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
    'users_count' => $this->whenCounted('users'),
    'created_at'  => $this->created_at?->toISOString(),
];
```

---

#### `back/app/Http/Requests/Roles/UpdateRoleRequest.php`
**Cambio:** Hacer `name` opcional (`sometimes`) para permitir updates de solo permisos en roles tenant.

**Antes:**
```php
'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->whereNot('guid', $guid)],
```

**Después:**
```php
'name' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('roles', 'name')->whereNot('guid', $guid)],
```

**Cambio en `messages()`:** quitar el mensaje de `name.required` (ya no aplica) o dejarlo como está (no genera error si no está).

**Nota de seguridad:** la regla `Rule::unique(...)->whereNot('guid', $guid)` sigue siendo necesaria para validar unicidad cuando `name` sí viene en el payload.

---

#### `back/app/Http/Controllers/V1/RoleController.php`
**Cambio:** La guardia `in_array($role->name, ['super-admin', 'admin'])` en `destroy()` se vuelve redundante con la nueva restricción del service (los roles platform sí se pueden eliminar, excepto super-admin y admin). Mantenerla como capa extra explícita para super-admin, pero el service también la cubre si se agrega a la lógica.

**Decisión:** conservar la guardia existente en `destroy()` tal cual, ya que protege explícitamente los roles de sistema críticos. La nueva restricción del service se suma como una capa adicional para todos los tenant roles. No se requiere cambio en el controller.

**Sin cambios adicionales en el controller.** El `makeFromException()` existente ya serializa `RoleImmutableException` (RuntimeException) con HTTP 422 si `ResponseHelper` lo mapea así. Verificar en el Paso 2 del orden de implementación.

---

#### `back/app/Helpers/ResponseHelper.php` (verificar y modificar si necesario)
**Cambio condicional:** Verificar cómo `makeFromException` mapea `RuntimeException`. Si no está mapeada explícitamente a 422, agregar:

```php
// En el switch/match de makeFromException:
case $exception instanceof \App\Exceptions\RoleImmutableException:
    return self::errorResponse(null, $exception->getMessage(), 422);
```

Si `ResponseHelper` ya tiene un fallback genérico para `RuntimeException` → 422, no es necesario este cambio. Leer el archivo antes de decidir.

---

#### `back/app/Exports/Roles/RolesExport.php`
**Cambio:** Agregar columna `type` al export de roles (opcional pero recomendado para consistencia).

```php
// En headings():
'type' => 'Tipo',

// En map():
'type' => $role->type,
```

Si el cliente de exports no selecciona esta columna, no aparece (ya usa `filterColumns`). Es un cambio aditivo sin impacto en exports existentes.

**Misma adición en `RolesPdfExporter.php` y `RolesTxtExporter.php`** siguiendo el mismo patrón.

---

### Migrations

**`back/database/migrations/2026_06_12_000001_add_type_to_roles_table.php`**

- Tabla: `roles`
- Columna: `type` string, default `'platform'`, NOT NULL, posición: después de `guard_name`
- Reversible: sí (`dropColumn('type')` en `down()`)
- Sin índice (tabla pequeña, consultas por type son infrecuentes)

---

### Rutas API
No se agregan ni modifican rutas. El contrato de los endpoints existentes cambia en comportamiento (restricciones), no en estructura.

---

### Permisos Spatie
No se agregan permisos nuevos. El permiso `roles.read`, `roles.create`, `roles.update`, `roles.delete` (si existen) no cambian. La diferenciación es por lógica de negocio, no por permisos adicionales.

---

### Contrato del endpoint (cambios en responses)

**PUT `/v1/roles/{guid}` — Update**

Request (sin cambios estructurales):
```json
{
  "permissions": ["uuid-1", "uuid-2"]
}
```
(Para roles tenant, `name` es omitido. Para roles platform, `name` sigue siendo enviable.)

Response 200 (sin cambios estructurales, campo nuevo en data):
```json
{
  "success": true,
  "data": {
    "guid": "...",
    "name": "vet",
    "type": "tenant",
    "permissions": [...],
    "created_at": "..."
  },
  "message": "Rol actualizado correctamente."
}
```

Response 422 — intento de renombrar rol tenant:
```json
{
  "success": false,
  "data": null,
  "message": "El nombre de un rol de tenant no puede modificarse.",
  "errors": null
}
```

**DELETE `/v1/roles/{guid}` — Destroy**

Response 422 — intento de eliminar rol tenant:
```json
{
  "success": false,
  "data": null,
  "message": "Los roles de tenant no pueden eliminarse.",
  "errors": null
}
```

**GET `/v1/roles` y GET `/v1/roles/{guid}` — List / Show**

Sin cambios estructurales. Ahora incluyen `"type": "platform"|"tenant"` en cada item del array `data`.

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — `RoleController`:**

1. `test_list_roles_includes_type_field` — verificar que la response de index incluye `type` en cada rol.
2. `test_show_role_includes_type_field` — verificar que show incluye `type`.
3. `test_platform_role_can_be_renamed` — PUT con `name` nuevo sobre rol platform → 200.
4. `test_tenant_role_name_cannot_be_changed` — PUT con `name` distinto sobre rol tenant → 422 con mensaje.
5. `test_tenant_role_name_same_value_is_ignored` — PUT con el mismo `name` sobre rol tenant → no explota, 200 (caso borde: si el frontend envía el name igual).
6. `test_tenant_role_permissions_can_be_updated` — PUT solo con `permissions` sobre rol tenant → 200.
7. `test_platform_role_permissions_can_be_updated` — PUT con permisos sobre rol platform → 200.
8. `test_tenant_role_cannot_be_deleted` — DELETE sobre rol tenant → 422.
9. `test_platform_role_can_be_deleted` — DELETE sobre rol platform (que no sea super-admin/admin) → 200.
10. `test_super_admin_and_admin_cannot_be_deleted` — guardia existente sigue funcionando.
11. `test_create_role_always_creates_platform_type` — POST → rol creado tiene `type = 'platform'`.

**Unit tests — `RoleService`:**

12. `test_update_tenant_role_with_different_name_throws_immutable_exception`.
13. `test_update_tenant_role_with_same_name_does_not_throw`.
14. `test_update_tenant_role_without_name_does_not_throw`.
15. `test_destroy_tenant_role_throws_immutable_exception`.
16. `test_destroy_platform_role_calls_repository`.
17. `test_create_always_sets_platform_type`.

---

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/roles/types/role.types.ts`
**Cambio:** Agregar `type` a `RoleItem` y hacer `name` opcional en `UpdateRolePayload`.

```typescript
export type RoleType = 'platform' | 'tenant'

export interface RoleItem {
  guid: string
  name: string
  type: RoleType          // campo nuevo
  permissions: PermissionItem[]
  users_count?: number
  created_at: string
}

// UpdateRolePayload: name pasa a ser opcional
export interface UpdateRolePayload {
  name?: string           // opcional para roles tenant
  permissions: string[]
}
```

#### `front/src/modules/roles/api/roles.mapper.ts`
**Cambio:** Mapear el campo `type` desde la respuesta de la API.

```typescript
export function toRoleItem(raw: Record<string, unknown>): RoleItem {
  // ...campos existentes...
  return {
    guid: raw['guid'] as string,
    name: raw['name'] as string,
    type: (raw['type'] as RoleType) ?? 'platform',  // campo nuevo
    permissions: rawPerms.map(...),
    users_count: raw['users_count'] as number | undefined,
    created_at: raw['created_at'] as string,
  }
}
```

#### `front/src/modules/roles/validators/role.validator.ts`
**Cambio:** Separar schema de creación y edición, o hacer `name` opcional en edición. Dado que el validador actual es compartido, crear dos schemas:

```typescript
// Schema para crear (name requerido)
export const createRoleSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(100, 'Máximo 100 caracteres'),
  permissions: z.array(z.string()).min(1, 'Seleccioná al menos un permiso'),
})

// Schema para editar (name opcional — para roles tenant)
export const updateRoleSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  permissions: z.array(z.string()).min(1, 'Seleccioná al menos un permiso'),
})

// Mantener alias para no romper imports existentes
export const roleSchema = createRoleSchema
export type RoleFormValues = z.infer<typeof createRoleSchema>
export type RoleUpdateFormValues = z.infer<typeof updateRoleSchema>
```

**Nota:** si el componente de formulario de edición (`RoleFormModal` o equivalente, buscar en Glob antes de implementar) usa `roleSchema` actualmente, debe actualizarse para usar `updateRoleSchema` cuando el `role.type === 'tenant'`.

#### Componentes de UI (identificar y actualizar)
**Tarea para el dev:** Buscar con Glob el/los componentes de formulario de edición de roles y el listado. En esos componentes:

1. En el formulario de edición: mostrar el campo `name` solo si `role.type === 'platform'`. Para roles tenant, solo mostrar el selector de permisos.
2. En el listado/tabla de roles: agregar una columna o badge que muestre el `type` del rol (`platform` → "Plataforma", `tenant` → "Tenant").
3. En el botón/acción de eliminar: deshabilitar o no mostrar si `role.type === 'tenant'`.
4. En el botón/acción de creación: los roles nuevos siempre son platform; no exponer selector de type en el form de creación.

**El dev debe hacer Glob sobre `front/src/modules/roles/` para encontrar los componentes `.vue` antes de implementar.** Este plan no los nombra porque no existen en el código relevado (solo se encontraron composables y tipos, no `.vue` files en la exploración).

---

## Orden de implementación

1. **Crear la migration** `2026_06_12_000001_add_type_to_roles_table.php` y correrla: `php artisan migrate`.

2. **Leer `back/app/Helpers/ResponseHelper.php`** y verificar cómo `makeFromException` mapea excepciones genéricas. Si no hay mapeo para `RuntimeException` → 422, agregar el bloque. Este paso debe hacerse ANTES de crear la excepción para no asumir el comportamiento.

3. **Crear `back/app/Exceptions/RoleImmutableException.php`** con el constructor que acepta un mensaje custom.

4. **Actualizar `back/app/Models/Role.php`**: agregar constantes, `$fillable`, `isPlatform()`, `isTenant()`, `scopePlatform()`, `scopeTenant()`. Agregar import de `Builder`.

5. **Actualizar `back/database/seeders/RoleSeeder.php`**: agregar `type` en cada `firstOrCreate` y el bloque de backfill al final del método `run()`. Correr `php artisan db:seed --class=RoleSeeder` para aplicar sobre la DB existente.

6. **Actualizar `back/app/Repositories/RoleRepositoryEloquent.php`**: modificar `create()` para incluir `type`, y limpiar `update()` para solo tocar `name` cuando viene en `$data`.

7. **Actualizar `back/app/Http/Requests/Roles/UpdateRoleRequest.php`**: cambiar `name` de `required` a `sometimes|nullable`.

8. **Actualizar `back/app/Services/RoleService.php`**: agregar lógica en `create()`, `update()` y `destroy()`. Agregar import de `RoleImmutableException`.

9. **Actualizar `back/app/Http/Resources/V1/RoleResource.php`**: agregar `'type' => $this->type`.

10. **Actualizar exports de roles** (`RolesExport.php`, `RolesPdfExporter.php`, `RolesTxtExporter.php`): agregar columna `type` (aditivo, no rompe nada existente).

11. **Correr tests de backend** para confirmar que los casos existentes siguen pasando y los nuevos pasan.

12. **Frontend — actualizar `front/src/modules/roles/types/role.types.ts`**: agregar `RoleType`, `type` en `RoleItem`, `name?` en `UpdateRolePayload`.

13. **Frontend — actualizar `front/src/modules/roles/api/roles.mapper.ts`**: mapear `type` en `toRoleItem()`.

14. **Frontend — actualizar `front/src/modules/roles/validators/role.validator.ts`**: separar `createRoleSchema` y `updateRoleSchema`.

15. **Frontend — identificar y actualizar componentes `.vue`**: buscar con Glob en `front/src/modules/roles/`, actualizar formulario de edición (ocultar `name` para tenant), listado (mostrar badge de type), y acciones (deshabilitar delete para tenant).

16. **Smoke test manual**: crear un rol platform, renombrarlo, eliminar. Abrir un rol tenant, intentar renombrarlo desde UI (debe estar deshabilitado), actualizar solo permisos, intentar eliminar (debe estar deshabilitado o mostrar error).

---

## Riesgos y consideraciones

**R-01 — ResponseHelper y el mapeo de RoleImmutableException**
Si `ResponseHelper::makeFromException()` no tiene un caso para `RuntimeException` y tiene un catch-all que retorna 500, el frontend recibirá un 500 en vez de 422. Leer el archivo en el Paso 2 del orden de implementación. Si no está mapeado, agregar el caso antes de continuar.

**R-02 — `SpatieRole` puede tener su propio `$fillable` o `$guarded`**
`SpatieRole` hereda de `Model` y puede declarar `$fillable` o `$guarded`. Si el model hijo sobreescribe `$fillable` y Spatie espera `name`/`guard_name` como asignables, puede haber conflicto en el `create()` del repositorio. Verificar el `SpatieRole` source antes de agregar `$fillable`. Si hay conflicto, usar `$model->forceFill(['type' => ...])` en el repositorio.

**R-03 — Backfill en producción**
La migration agrega `default('platform')` a todos los roles existentes, incluyendo los tenant. El seeder backfill corrige esto. Si el seeder NO se corre en producción tras la migration, los roles tenant quedarán con `type = 'platform'` y no tendrán las restricciones. El deploy debe incluir `php artisan db:seed --class=RoleSeeder` explícitamente.

**R-04 — Tests existentes pueden fallar**
Los tests existentes de RoleController que llaman a `PUT /v1/roles/{guid}` con `name` como campo `required` (si los hay) deberán actualizarse para enviar `name` o ajustar la expectativa. Del mismo modo, tests que intenten eliminar roles por nombre hardcodeado deben verificar que sean de type `platform`.

**R-05 — El campo `type` no está en el `CreateRoleRequest`**
Por diseño (DEC-07), el usuario no puede elegir el type al crear. Pero si un test o un actor externo envía `type` en el body del POST, el campo llega a `$request->validated()` y luego al service, que lo sobrescribe con `'platform'`. No es un problema, pero es ruido. Si se quiere ser explícito, agregar `'type' => ['prohibited']` al `CreateRoleRequest` para rechazar el campo si viene.

**R-06 — Componentes `.vue` no explorados**
No se encontraron archivos `.vue` en `front/src/modules/roles/` durante la exploración (solo `.ts`). El dev debe hacer Glob para localizarlos. Es posible que los componentes estén en un directorio `views/` o `components/` fuera del módulo. Si no existen aún, la lógica de UI para diferenciar tipos es futura.

**R-07 — Multi-tenant (no aplica directamente)**
El sistema de roles no tiene scope de tenant (los roles son globales en la tabla, sin `team_foreign_key` activo en la config de Spatie). Este plan no introduce ni rompe aislamiento multi-tenant. Sin embargo, al avanzar hacia multi-tenancy real de permisos por vet, este campo `type` será parte del modelo de diferenciación.

**R-08 — Exports incluyen campo `type` (aditivo)**
Las columnas de export se seleccionan desde el frontend. El campo `type` solo aparece si se agrega en el array `ROLE_EXPORT_COLUMNS` del enum del frontend. Por ahora, el dev puede omitir esta adición en el FE si no es parte del alcance de esta iteración.

---

## Pendientes / fuera de alcance

- **Filtrar roles por type en el endpoint de listado**: agregar `?type=platform|tenant` como query param al endpoint `GET /v1/roles` para que el frontend pueda listar solo roles tenant (para asignar a usuarios veterinarios) o solo platform (para gestión interna). No está en el requerimiento actual pero es el siguiente paso natural.
- **Validación de asignación de roles**: asegurarse de que un usuario de plataforma no pueda recibir un rol tenant y viceversa. Esto involucra `UserRoleController` y `AssignRolesRequest`, y requiere un ticket separado.
- **`type` en la columna de export del frontend**: agregar `{ key: 'type', label: 'Tipo' }` a `ROLE_EXPORT_COLUMNS` en `role.enums.ts` para que aparezca como opción seleccionable en el export. Es trivial pero queda fuera de este alcance.
- **Enum PHP de RoleType**: si en el futuro se suman más tipos, migrar las constantes del model a un `BackedEnum`. Dejado para cuando haya más de dos valores.
