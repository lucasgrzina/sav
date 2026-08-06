# Plan técnico: Replicar/Clonar Protocolo (SuperAdmin)

## Input procesado
Brief informal del usuario (ya validado, acotado). Módulo base ya implementado — ver
`.claude/docs/plans/protocolos-superadmin-abm-plan.md` para las convenciones originales.

## Resumen ejecutivo
Se agrega una acción "Replicar" al listado de Protocolos (`TechniqueProtocolsTab.vue`) que
clona un protocolo completo (tareas + alertas) bajo un nuevo `name` con sufijo `(copia)`,
resolviendo colisiones de nombre en el backend con un algoritmo de incremento. Se reutiliza
al máximo el código existente: `ProtocolService::create()` + `syncTasks()`/`syncAlerts()` ya
soportan inserción pura cuando no se envían guids, así que el clonado arma un payload sin
guids y delega en `create()` en vez de escribir lógica de inserción paralela. Nuevo endpoint
`POST /v1/admin/protocols/{guid}/replicate`, sin FormRequest (no hay input de usuario), mismo
permiso `protocols.create`.

## Decisiones tomadas

DEC-01 — Endpoint y verbo
  Decisión: `POST /v1/admin/protocols/{guid}/replicate`, sin body.
  Justificación: la acción no es idempotente (cada llamada crea un protocolo nuevo) y no
  tiene input de usuario — un POST de acción sobre un recurso existente es el patrón estándar
  REST para este tipo de operación ("verb-like" sub-resource), consistente con `guid` en la URL
  como manda la regla dura #6 del dominio.
  Alternativa descartada: `POST /v1/admin/protocols` con un flag `source_guid` en el body —
  descartada porque mezclaría dos contratos (crear desde cero vs. clonar) en el mismo endpoint
  y complicaría el `StoreProtocolRequest` sin necesidad, ya que el clon no requiere validación
  de campos de usuario.

DEC-02 — Permiso requerido
  Decisión: reutilizar `protocols.create` (NO crear `protocols.replicate`).
  Justificación: semánticamente, replicar es una forma de "crear un protocolo nuevo" (incluso
  reutiliza `ProtocolService::create()` internamente). Crear un permiso granular nuevo para una
  variante de creación agrega complejidad de mantenimiento (seeder, roles, docs) sin que exista
  hoy un caso de negocio donde alguien pueda crear pero no replicar (o viceversa). Actualmente
  solo `super-admin` tiene estos permisos (`ProtocolPermissionsSeeder` hace
  `syncPermissions(Permission::all())` sobre ese rol), así que no hay ganancia real de
  granularidad todavía.
  Alternativa descartada: `protocols.replicate` nuevo — descartada por sobre-ingeniería; se
  puede introducir después si aparece un caso de negocio real que lo justifique.

DEC-03 — Reutilizar `create()` en vez de escribir un "clonador" paralelo
  Decisión: `ProtocolService::replicate()` arma un array de datos (`technique_id`,
  `country_id`, `color`, `name` resuelto, `tasks` con sus `alerts` mapeadas SIN `guid`) y
  delega en `$this->create($data, $createdById)`.
  Justificación: `syncTasks()`/`syncAlerts()` ya manejan el caso "sin guid entrante = crear
  fila nueva" — es exactamente lo que necesita un clon. Escribir un método de inserción
  paralelo duplicaría lógica ya testeada (58 tests backend pasando) y sería una superficie
  nueva de bugs. Esto también garantiza que el clon respeta automáticamente cualquier regla
  futura que se agregue a `create()` (p. ej. defaults de campos).
  Alternativa descartada: usar `Model::replicate()` de Eloquent + clonar relaciones a mano —
  descartada porque `replicate()` nativo no clona relaciones `hasMany` anidadas (`tasks.alerts`)
  y requeriría lógica manual de todos modos, sin la ventaja de reusar `syncTasks`.

DEC-04 — Algoritmo de resolución de nombre
  Decisión: método privado `resolveReplicateName(Protocol $protocol): string` en
  `ProtocolService`. Prueba `"{name} (copia)"`; si `isDuplicateName()` da true, prueba
  `"{name} (copia 2)"`, `"{name} (copia 3)"`, etc., incrementando hasta encontrar uno libre.
  Justificación: exactamente lo pedido por el usuario, implementado con el método
  `isDuplicateName()` ya existente (wrapper de `existsDuplicate()` del repository) — no
  requiere tocar el repository.
  Nota de robustez: existe un unique constraint compuesto a nivel DB
  (`protocols_technique_country_name_unique` en `technique_id+country_id+name`, ver migración
  `2026_07_20_000001_create_protocols_table.php`). El chequeo pre-insert en el Service es
  best-effort contra una carrera cliente-servidor normal (dos réplicas casi simultáneas del
  mismo protocolo); el constraint de DB es el backstop real de integridad. Si dos requests
  colisionan en el mismo milisegundo, el segundo fallará con `QueryException` (SQLSTATE 23000)
  y caerá en el catch genérico del controller (`makeFromException`) devolviendo un error 500 —
  aceptable como edge case, no se implementa retry automático (ver Riesgos).

DEC-05 — Autoría del clon (`created_by_type`/`created_by_id`)
  Decisión: NO copiar el autor original. El clon se crea con el mismo criterio que
  `create()` normal: `created_by_type = 'superadmin'`, `created_by_id = $request->user()->id`
  (el SuperAdmin autenticado que ejecuta la replicación).
  Justificación: es consistente con la semántica de auditoría — quien ejecuta la acción de
  crear (aunque sea "crear por clonación") es el autor del registro nuevo. Al delegar en
  `create()`, esto ya sale gratis sin código adicional: `create()` ya setea estos campos con
  `$createdById` recibido por parámetro.

DEC-06 — Response del endpoint
  Decisión: devolver `ProtocolResource` completo del protocolo recién creado, código 201,
  mismo formato que `store()`.
  Justificación: el frontend invalida `['admin-protocols']` e informa éxito; no necesita
  navegar directamente al detalle, pero devolver el resource completo es consistente con el
  resto del CRUD y no cuesta nada extra (ya se carga con `fresh()->load(...)` dentro de
  `create()`).

## Cambios en BACKEND

### Archivos a modificar

#### `back/app/Services/ProtocolService.php`
**Cambio:** agregar método público `replicate()` y dos privados de soporte.
**Después (pseudocódigo, mismo estilo que el resto del archivo):**
```php
/** Clona un protocolo completo (tasks + alerts) con nombre resuelto por incremento. */
public function replicate(Protocol $protocol, int $createdById): Protocol
{
    $protocol->loadMissing('tasks.alerts');

    $data = [
        'technique_id' => $protocol->technique_id,
        'country_id'   => $protocol->country_id,
        'name'         => $this->resolveReplicateName($protocol),
        'color'        => $protocol->color,
        'tasks'        => $this->mapTasksForClone($protocol->tasks),
    ];

    return $this->create($data, $createdById);
}

/** DEC-04: "{name} (copia)", "(copia 2)", "(copia 3)"... hasta encontrar uno libre. */
private function resolveReplicateName(Protocol $protocol): string
{
    $candidate = $protocol->name . ' (copia)';
    $suffix = 2;

    while ($this->isDuplicateName($protocol->technique_id, $protocol->country_id, $candidate)) {
        $candidate = $protocol->name . " (copia {$suffix})";
        $suffix++;
    }

    return $candidate;
}

/** Mapea tasks/alerts a arrays SIN guid → syncTasks()/syncAlerts() los trata como inserts nuevos. */
private function mapTasksForClone(\Illuminate\Support\Collection $tasks): array
{
    return $tasks->map(fn (ProtocolTask $task) => [
        'description' => $task->description,
        'days_offset' => $task->days_offset,
        'time_of_day' => $task->time_of_day,
        'time'        => $task->time,
        'important'   => $task->important,
        'alerts'      => $task->alerts->map(fn (ProtocolTaskAlert $alert) => [
            'offset_days'          => $alert->offset_days,
            'time_of_day'          => $alert->time_of_day,
            'time'                 => $alert->time,
            'roles'                => $alert->roles,
            'message'              => $alert->message,
            'require_confirmation' => $alert->require_confirmation,
        ])->all(),
    ])->all();
}
```
**Dependencias:** ninguna nueva; usa `isDuplicateName()` y `create()` ya existentes en la
misma clase. No requiere tocar `ProtocolRepositoryInterface` ni
`ProtocolRepositoryEloquent`.

#### `back/app/Http/Controllers/V1/AdminProtocolController.php`
**Cambio:** agregar método `replicate()`.
**Después:**
```php
public function replicate(string $guid): JsonResponse
{
    try {
        $protocol = $this->protocolService->findByGuidWithTasks($guid);
        if (!$protocol) {
            return $this->makeNotFound('Protocolo no encontrado.');
        }

        $clone = $this->protocolService->replicate($protocol, request()->user()->id);

        return $this->makeSuccess(new ProtocolResource($clone), 'Protocolo replicado correctamente.', 201);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```
Nota: usar `request()->user()->id` (o inyectar `Request $request` como parámetro del método,
más explícito y consistente con `store()`/`update()` que ya reciben `$request` tipado —
preferir esta segunda forma: `public function replicate(Request $request, string $guid)`).

#### `back/routes/api/protocols.php`
**Cambio:** agregar ruta nueva dentro del mismo grupo, con el mismo permiso que `store`.
```php
Route::post('/{guid}/replicate', [AdminProtocolController::class, 'replicate'])
    ->middleware('can:protocols.create');
```
Ubicarla después de la ruta `POST /` y antes de `GET /{guid}` para mantener agrupados los
verbos de escritura, o al final del grupo — cualquiera es válido, mantener junto a `store`
por claridad semántica (ambas usan `protocols.create`).

### Migrations
Ninguna. No se agregan columnas ni tablas nuevas.

### Rutas API
| Método | Path | Controller@action | Middleware | Permiso |
|---|---|---|---|---|
| POST | `/v1/admin/protocols/{guid}/replicate` | `AdminProtocolController@replicate` | `auth:sanctum`, `can:protocols.create` | `protocols.create` (DEC-02) |

### Permisos Spatie
Ninguno nuevo (DEC-02). No se toca `ProtocolPermissionsSeeder.php`.

### Contrato del endpoint
**Request:** `POST /v1/admin/protocols/{guid}/replicate` — sin body.

**Response 201:**
```json
{
  "success": true,
  "message": "Protocolo replicado correctamente.",
  "data": {
    "guid": "<nuevo-guid>",
    "name": "Nombre original (copia)",
    "color": "...",
    "technique": { "guid": "...", "name": "..." },
    "country": { "guid": "...", "name": "..." } ,
    "is_global": false,
    "tasks_count": 3,
    "created_by_type": "superadmin",
    "tasks": [ /* ProtocolTaskResource[], con nuevos guids en tasks y alerts */ ],
    "created_at": "...",
    "updated_at": "..."
  }
}
```

**Errores posibles:**
- `404` — protocolo origen (`{guid}`) no encontrado → `makeNotFound`.
- `403` — usuario sin permiso `protocols.create` → manejado por middleware `can:`.
- `500` — colisión de unique constraint en carrera extrema (ver DEC-04, no hay manejo
  especial, cae en `makeFromException`).

### Tests a generar
En `back/tests/Feature/AdminProtocolControllerTest.php`:
- `replicate()` devuelve 201 con un protocolo nuevo (guid distinto al original).
- El clon tiene `name` = `"{original} (copia)"` cuando no hay colisión.
- El clon tiene mismo `technique_id`/`country_id` que el original.
- El clon tiene la misma cantidad de `tasks` y `alerts`, con TODOS los campos de negocio
  iguales (`days_offset`, `time_of_day`, `time`, `important`, `roles`, `message`,
  `require_confirmation`) pero con guids nuevos y distintos de los del original.
- El clon tiene `created_by_id` = usuario autenticado que ejecuta la replicación (no el autor
  original, si se testea con un protocolo creado por otro usuario).
- 404 si el `guid` no existe.
- 403 si el usuario autenticado no tiene `protocols.create`.

En `back/tests/Unit/ProtocolServiceTest.php`:
- `resolveReplicateName()` (vía `replicate()`, testeando el resultado): sin colisión → sufijo
  `(copia)`; con 1 colisión existente → `(copia 2)`; con colisiones en `(copia)` y
  `(copia 2)` → `(copia 3)`.
- `replicate()` no muta el protocolo original (verificar que sigue existiendo con sus datos
  intactos tras la operación).

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/protocols/types/protocol.types.ts`
**Cambio:** no requiere tipos nuevos — la respuesta del endpoint reusa `ProtocolDetail`
(mismo shape que `create`/`update`). No hay payload de request (sin body).

#### `front/src/modules/protocols/api/protocol.api.ts`
**Cambio:** agregar función nueva, mismo patrón que las 4 existentes.
```typescript
export async function adminReplicateProtocolApi(guid: string): Promise<ProtocolDetail> {
  const res = await http.post<ProtocolDetail>(`/v1/admin/protocols/${guid}/replicate`)
  return res.data
}
```

#### `front/src/modules/protocols/composables/useProtocolMutations.ts`
**Cambio:** agregar `useReplicateProtocol()`, mismo patrón que `useCreateProtocol()` (sin
`fieldErrors` porque no hay formulario/body que pueda fallar validación — solo error general).
```typescript
import { adminReplicateProtocolApi } from '../api/protocol.api'
// ...agregar al import existente de protocol.api

export function useReplicateProtocol() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => adminReplicateProtocolApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-protocols'] })
      success('Protocolo replicado correctamente')
    },
    onError: () => {
      error('Error al replicar el protocolo')
    },
  })

  return { ...mutation }
}
```
No requiere wrapper "WithModal" como `useDeleteProtocolWithModal` — no hay confirmación
modal pedida por el usuario (acción directa de un click, igual que hoy no existe
confirmación para "editar"). Si el usuario luego pide confirmación, se puede envolver en un
`BaseConfirmDialog` sin tocar el composable.

#### `front/src/modules/techniques/components/TechniqueProtocolsTab.vue`
**Cambio:** agregar botón "Replicar" en la columna de acciones, entre editar y eliminar (o
después de eliminar — mantener editar/eliminar/replicar en ese orden visual es razonable
porque replicar es menos frecuente que editar). Usar ícono `CopyOutlined` de
`@ant-design/icons-vue` (ya se usa `@ant-design/icons-vue` en el archivo, agregar el import).

**Imports a agregar:**
```typescript
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined } from '@ant-design/icons-vue'
import { useReplicateProtocol } from '@/modules/protocols/composables/useProtocolMutations'
// agregar useReplicateProtocol al import existente de useProtocolMutations
```

**Composable + handler a agregar (junto a la sección "--- Eliminar ---"):**
```typescript
// --- Replicar ---
const { mutate: mutateReplicate, isPending: isReplicating } = useReplicateProtocol()

function replicateProtocol(protocol: ProtocolListItem) {
  mutateReplicate(protocol.guid)
}
```

**Template — dentro de `BaseTableActions`, después del botón editar:**
```vue
<PermissionGuard permission="protocols.create">
  <BaseButton
    variant="row-action"
    size="small"
    tooltip="Replicar protocolo"
    :loading="isReplicating"
    @click="replicateProtocol(record as ProtocolListItem)"
  >
    <template #icon><CopyOutlined /></template>
  </BaseButton>
</PermissionGuard>
```
Nota: `PermissionGuard` con `protocols.create` (DEC-02), NO `protocols.update`.

**Decisión de UX — sin confirmación modal:** el usuario no pidió confirmación previa a
replicar (a diferencia de eliminar, que sí la tiene por ser destructivo). Replicar es no
destructivo — en el peor caso crea un registro de más que se puede eliminar. Se ejecuta al
click directo, igual que hoy no hay confirmación para abrir el drawer de edición.

### Tests a generar
Ninguno de tipo unitario nuevo estrictamente necesario dado el patrón ya cubierto por los
tests existentes de `useCreateProtocol`, pero si el proyecto tiene tests de componente para
`TechniqueProtocolsTab.vue`, agregar: click en "Replicar" llama a `adminReplicateProtocolApi`
con el guid correcto y dispara invalidación de `['admin-protocols']`.

## Orden de implementación
1. Backend: agregar `replicate()`, `resolveReplicateName()`, `mapTasksForClone()` a
   `ProtocolService.php`.
2. Backend: agregar método `replicate()` a `AdminProtocolController.php`.
3. Backend: agregar ruta `POST /{guid}/replicate` en `routes/api/protocols.php`.
4. Backend: escribir/correr tests de `AdminProtocolControllerTest.php` y
   `ProtocolServiceTest.php` (casos listados arriba).
5. Frontend: agregar `adminReplicateProtocolApi()` en `protocol.api.ts`.
6. Frontend: agregar `useReplicateProtocol()` en `useProtocolMutations.ts`.
7. Frontend: agregar botón "Replicar" + handler en `TechniqueProtocolsTab.vue`.
8. Verificación manual end-to-end: replicar un protocolo con 2+ tareas y alertas anidadas,
   confirmar guids nuevos, nombre con sufijo, y comportamiento del incremento replicando el
   mismo protocolo 3 veces seguidas.

## Riesgos y consideraciones
- **Carrera en el algoritmo de nombre (DEC-04):** el pre-check + increment no es 100%
  atómico contra requests concurrentes idénticas; el unique constraint de DB es el backstop
  real, pero una colisión en esa ventana produce un 500 sin mensaje de negocio claro para el
  usuario. Aceptable dado que es un flujo de panel administrativo, no de alto tráfico
  concurrente sobre el mismo protocolo. Si se vuelve un problema real, se puede envolver
  `create()` en un catch de `QueryException` (código 23000) con un retry único del algoritmo
  de nombre.
- **Multi-tenant:** no aplica — el módulo Protocolos SuperAdmin ya opera fuera del scope de
  tenant (protocolos globales o con `vet_id = null`, ver comentario en la migración). No se
  introduce ninguna fuga de tenant nueva.
- **Multi-país:** el clon preserva `country_id` tal cual (mismo país o global si era global).
  No hay lógica nueva a validar por país.
- **`ProtocolTaskResource`/`ProtocolTaskAlert` cast `roles` como array:** al clonar, `roles`
  se pasa como array PHP (ya casteado desde el modelo original), compatible con lo que
  `syncAlerts()` espera (`$alertData['roles']`) sin transformación adicional.
- **Nombres de rutas:** verificar que no exista ya una ruta con nombre conflictivo al usar
  `Route::post('/{guid}/replicate', ...)` bajo el prefijo `v1/admin/protocols` — no hay
  colisión, es un segmento nuevo.

## Pendientes / fuera de alcance
- Confirmación modal antes de replicar (no pedida; se puede agregar después envolviendo el
  `mutateReplicate` existente).
- Permiso granular `protocols.replicate` (DEC-02) — reevaluar si en el futuro aparece un rol
  que deba crear pero no clonar, o viceversa.
- Replicar hacia otra técnica/país distinto del original (hoy el clon preserva exactamente
  `technique_id`/`country_id` — si se pide "clonar y reasignar", es una iteración futura con
  un formulario de destino).
