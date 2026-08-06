# Plan técnico: Simular fecha en Protocolo (SuperAdmin)

## Input procesado
`.claude/docs/tickets/TKT-004-simular-fecha-protocolo.md`

## Resumen ejecutivo
Se agrega una acción "Simular" en `TechniqueProtocolsTab.vue` que abre un drawer nuevo
(`ProtocolSimulateDrawer.vue`) con un `a-date-picker` de fecha base. Al elegir la fecha, el
frontend llama `GET /v1/admin/protocols/{guid}/simulate?base_date=YYYY-MM-DD`, que devuelve —
sin persistir nada — el cronograma calculado de Tareas y Alertas del protocolo. El cálculo vive
en un método nuevo `ProtocolService::simulate()` (mismo service que `replicate()`), expuesto por
un Resource nuevo `ProtocolSimulationResource`. El frontend renderiza dos `BaseDataTable`
(Tareas y Alertas) ordenadas cronológicamente, resaltando filas `important`/`require_confirmation`
vía la prop nativa `row-class-name` de Ant Design Table (ya expuesta por `BaseDataTable` al hacer
`v-bind="props"` de `TableProps`). Reutiliza el permiso `protocols.update` existente, sin permiso
nuevo.

## Decisiones tomadas

DEC-01 — Shape de la respuesta del endpoint `simulate` (investigación previa #1)
  Decisión: Resource nuevo `ProtocolSimulationResource` (no un `array` crudo, no reutilizar
  `ProtocolResource`), con este shape:
  ```json
  {
    "success": true,
    "data": {
      "protocol": { "guid": "...", "name": "..." },
      "base_date": "2026-08-15",
      "tasks": [
        {
          "guid": "task-guid",
          "description": "...",
          "important": true,
          "computed_date": "2026-08-18",
          "computed_time": "09:00",
          "alerts": [
            {
              "guid": "alert-guid",
              "message": "...",
              "roles": ["vet", "client-owner"],
              "require_confirmation": true,
              "computed_date": "2026-08-17",
              "computed_time": "08:00"
            }
          ]
        }
      ],
      "alerts": [
        {
          "guid": "alert-guid",
          "task_description": "...",
          "message": "...",
          "roles": ["vet", "client-owner"],
          "require_confirmation": true,
          "computed_date": "2026-08-17",
          "computed_time": "08:00"
        }
      ]
    }
  }
  ```
  Se devuelven AMBAS colecciones (`tasks` con `alerts` anidadas, y `alerts` como lista plana con
  `task_description` agregado) — no una sola. `tasks` alimenta la tabla de Tareas del drawer,
  `alerts` (plana, ya ordenada cronológicamente entre TODAS las tareas) alimenta la tabla de
  Alertas, tal como pide el layout de referencia (dos tablas independientes, no una tabla anidada
  por tarea). Esto evita que el frontend tenga que aplanar `tasks[].alerts[]` a mano con
  `flatMap` + re-ordenar — el shape ya viene listo para bindear directo a cada `BaseDataTable`.
  Justificación: cumple DEC-NEG-04 del ticket ("estructura con dos colecciones... y opcionalmente
  una lista plana de alertas... el arquitecto define el shape exacto") — se elige explícitamente
  incluir ambas porque el layout de referencia (`simular_fecha.jpg`) muestra dos tablas
  independientes, y la tabla de Alertas necesita `task_description` (columna "Tarea asociada")
  que no está disponible si el frontend solo recibe `tasks[].alerts[]`.
  Alternativa descartada: devolver solo `tasks` con `alerts` anidadas y aplanar en el cliente —
  descartada porque viola la regla dura #8 (el frontend no arma lógica de negocio/transformación
  de datos; el backend ya tiene toda la información para entregar el shape listo para pintar) y
  porque `days_offset`/`offset_days` son cálculos de dominio (regla dura #1) que no deben
  reconstruirse ni reordenarse en el cliente.

DEC-02 — Endpoint y query param (investigación previa #2)
  Decisión: `GET /v1/admin/protocols/{guid}/simulate?base_date=YYYY-MM-DD`, validado con un
  FormRequest nuevo `SimulateProtocolRequest`:
  ```php
  public function rules(): array
  {
      return ['base_date' => ['required', 'date_format:Y-m-d']];
  }
  ```
  Justificación: `date_format:Y-m-d` (no `date` genérico) fuerza el formato exacto que el
  frontend envía (`dayjs(...).format('YYYY-MM-DD')`), evitando ambigüedades de parseo
  (`15/08/2026` vs `2026-08-15` vs timestamps). Mensaje de error en español vía `messages()`:
  `'base_date.required' => 'La fecha base es obligatoria.', 'base_date.date_format' => 'La fecha base debe tener el formato AAAA-MM-DD.'`.
  Alternativa descartada: regla `date` de Laravel sin `date_format` — descartada porque acepta
  formatos ambiguos y el ticket exige "Form Request con `date` requerido" como mínimo, pero
  `date_format:Y-m-d` es un superset más estricto que sigue siendo "date requerido" y elimina
  ambigüedad real de parseo cross-timezone.

DEC-03 — Ubicación de la lógica de cálculo
  Decisión: método público `simulate(Protocol $protocol, Carbon $baseDate): array` en
  `ProtocolService.php` (mismo archivo que `replicate()`), con dos privados de soporte:
  `calculateTaskDate(Carbon $baseDate, ProtocolTask $task): Carbon` y
  `calculateAlertDate(Carbon $taskDate, ProtocolTaskAlert $alert): Carbon`.
  Justificación: sigue el patrón ya usado en el módulo (DEC-NEG-04 del ticket lo pide
  explícitamente) — reutiliza `Protocol::tasks()` (ya ordenado por `sort_order`) y
  `ProtocolTask::alerts()` (ya ordenado por `sort_order`) sin tocar el repository, porque es
  puro cálculo sobre relaciones ya cargadas, no una query nueva.

DEC-04 — Fórmula de cálculo (pseudocódigo exacto)
  Decisión:
  ```php
  private function calculateTaskDate(Carbon $baseDate, ProtocolTask $task): Carbon
  {
      $date = $task->time_of_day === 'after'
          ? $baseDate->copy()->addDays($task->days_offset)
          : $baseDate->copy()->subDays($task->days_offset);

      return $this->applyTime($date, $task->time);
  }

  private function calculateAlertDate(Carbon $taskDate, ProtocolTaskAlert $alert): Carbon
  {
      $date = $alert->time_of_day === 'after'
          ? $taskDate->copy()->addDays($alert->offset_days)
          : $taskDate->copy()->subDays($alert->offset_days);

      return $this->applyTime($date, $alert->time);
  }

  private function applyTime(Carbon $date, ?string $time): Carbon
  {
      if (!$time) {
          return $date->startOfDay();
      }
      [$h, $m] = explode(':', substr($time, 0, 5));
      return $date->setTime((int) $h, (int) $m);
  }
  ```
  Nota crítica: `calculateAlertDate` recibe la `$taskDate` YA CALCULADA en el paso anterior
  (`Carbon` con fecha+hora de la tarea), NO `$baseDate` — esto es DEC-NEG-03 del ticket
  ("relativa a la fecha de la tarea calculada, NO a `base_date` directamente"). Usar
  `->copy()` en cada paso para no mutar el `Carbon` compartido entre tareas/alertas del mismo
  `foreach`.
  Justificación: implementación directa de DEC-NEG-03, sin desviaciones. `applyTime` extrae
  hora/minuto de un string `H:i:s` o `H:i` (formato que ya usa `time` en `ProtocolTaskResource`,
  ver `substr($this->time, 0, 5)`).

DEC-05 — Orden cronológico de ambas colecciones
  Decisión: `tasks` ordenadas por `computed_date` (fecha+hora combinada) ascendente; `alerts`
  (lista plana) ordenadas igual, ambas con `->sortBy()` sobre el `Carbon` calculado ANTES de
  pasar por el Resource (el Resource no ordena, solo formatea).
  Justificación: DEC-NEG-03 del ticket exige orden cronológico explícito; ordenar en el Service
  (antes de envolver en Resource) mantiene el Resource como responsable solo de formateo,
  consistente con el resto del módulo.

DEC-06 — Dónde vive la lógica de highlight en frontend (investigación previa #3)
  Decisión: prop nativa `row-class-name` de Ant Design Vue `Table`, pasada directamente al
  `BaseDataTable` (que ya reenvía `TableProps<T>` completo vía `v-bind="props"` — confirmado en
  `front/src/components/tables/BaseDataTable.vue`, no requiere cambios en el átomo). Función
  computada en `ProtocolSimulateDrawer.vue`:
  ```typescript
  function taskRowClassName(record: SimulatedTask): string {
    return record.important ? 'psd-row--important' : ''
  }
  function alertRowClassName(record: SimulatedAlert): string {
    return record.require_confirmation ? 'psd-row--critical' : ''
  }
  ```
  con clases CSS scoped `:deep(.psd-row--important)`/`:deep(.psd-row--critical)` (fondo tenue +
  borde izquierdo de color, sin ícono adicional — mínimo viable, el ticket no exige ícono
  específico).
  Justificación: es la forma idiomática de Ant Design Vue Table para resaltar filas
  condicionalmente sin reimplementar el body de la tabla ni tocar `BaseDataTable` (que debe
  seguir siendo agnóstico de reglas de negocio, por convención de átomos). No requiere
  `bodyCell` custom porque el highlight es a nivel de fila completa, no de celda.
  Alternativa descartada: clase condicional directa en un `<template #bodyCell>` custom por
  celda — descartada porque requeriría repetir la condición en cada columna en vez de una sola
  vez a nivel fila, y Ant Design Vue ya resuelve esto nativamente con `row-class-name`.

DEC-07 — Permiso y guard
  Decisión: reutilizar `protocols.update` (DEC-NEG-07 del ticket, no negociable). Ruta protegida
  con `->middleware('can:protocols.update')`, botón envuelto en
  `<PermissionGuard permission="protocols.update">`.

DEC-08 — Verbo del endpoint y no persistencia
  Decisión: `GET`, confirmado por DEC-NEG-02/DEC-NEG-01 del ticket. El controller NO abre
  `DB::transaction()` (a diferencia de `create`/`update`/`replicate`) porque no escribe nada —
  solo lee `Protocol` con `tasks.alerts` y calcula en memoria.

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Http/Requests/Protocols/SimulateProtocolRequest.php`
**Propósito:** validar `base_date` como query param.
**Firma:**
```php
class SimulateProtocolRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['base_date' => ['required', 'date_format:Y-m-d']];
    }

    public function messages(): array
    {
        return [
            'base_date.required'    => 'La fecha base es obligatoria.',
            'base_date.date_format' => 'La fecha base debe tener el formato AAAA-MM-DD.',
        ];
    }
}
```

#### `back/app/Http/Resources/V1/ProtocolSimulationResource.php`
**Propósito:** shape de respuesta de DEC-01. Recibe un array (no un modelo Eloquent) desde el
Service, así que extiende `JsonResource` pero se construye con `new ProtocolSimulationResource((object) $data)`
o, más simple, el Service devuelve directamente el array y el Resource se aplica solo sobre
`protocol` (sub-resource liviano); el resto (`tasks`, `alerts`) ya viene armado como array plano
listo desde el Service (ver DEC-09 más abajo sobre por qué NO se usan Resources anidados para
`tasks`/`alerts` acá).

**Firma:**
```php
class ProtocolSimulationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'protocol'  => ['guid' => $this['protocol']->guid, 'name' => $this['protocol']->name],
            'base_date' => $this['base_date'],
            'tasks'     => $this['tasks'],
            'alerts'    => $this['alerts'],
        ];
    }
}
```

DEC-09 — Por qué NO se reutilizan `ProtocolTaskResource`/`ProtocolTaskAlertResource`
  Decisión: `simulate()` en el Service arma directamente arrays planos con las claves de DEC-01
  (`computed_date`, `computed_time`, etc.), NO instancias de `ProtocolTask`/`ProtocolTaskAlert`
  con campos calculados inyectados. `ProtocolTaskResource`/`ProtocolTaskAlertResource` exponen
  `days_offset`/`time_of_day`/`offset_days` (los campos CRUD), que no tienen sentido en el
  contexto de "fecha ya calculada" del drawer de simulación — mezclar ambos shapes en el mismo
  Resource confundiría al frontend sobre qué campo usar para pintar. Se prefieren arrays simples
  construidos ad-hoc en el Service, devueltos tal cual por `ProtocolSimulationResource`.

### Archivos a modificar

#### `back/app/Services/ProtocolService.php`
**Cambio:** agregar método público `simulate()` + 3 privados de soporte (junto a la sección de
`replicate()`, mismo estilo de comentarios PHPDoc de una línea).
```php
use Illuminate\Support\Carbon;

/**
 * Calcula el cronograma de Tareas/Alertas del protocolo a partir de una fecha base,
 * SIN persistir nada (DEC-NEG-01). Devuelve dos colecciones: tasks (con alerts anidadas)
 * y alerts (lista plana con task_description), ambas ordenadas cronológicamente.
 */
public function simulate(Protocol $protocol, Carbon $baseDate): array
{
    $protocol->loadMissing('tasks.alerts');

    $simulatedTasks = collect();
    $simulatedAlerts = collect();

    foreach ($protocol->tasks as $task) {
        $taskDate = $this->calculateTaskDate($baseDate, $task);

        $taskAlerts = $task->alerts->map(function (ProtocolTaskAlert $alert) use ($taskDate, $task) {
            $alertDate = $this->calculateAlertDate($taskDate, $alert);
            return [
                'guid'                  => $alert->guid,
                'task_description'      => $task->description,
                'message'               => $alert->message,
                'roles'                 => $alert->roles,
                'require_confirmation'  => $alert->require_confirmation,
                'computed_date'         => $alertDate->toDateString(),
                'computed_time'         => $alertDate->format('H:i'),
                '_sort'                 => $alertDate,
            ];
        });

        $simulatedAlerts = $simulatedAlerts->concat($taskAlerts);

        $simulatedTasks->push([
            'guid'           => $task->guid,
            'description'    => $task->description,
            'important'      => $task->important,
            'computed_date'  => $taskDate->toDateString(),
            'computed_time'  => $taskDate->format('H:i'),
            'alerts'         => $taskAlerts->map(fn ($a) => collect($a)->except('_sort')->all())->values()->all(),
            '_sort'          => $taskDate,
        ]);
    }

    return [
        'protocol'  => $protocol,
        'base_date' => $baseDate->toDateString(),
        'tasks'     => $simulatedTasks->sortBy('_sort')->map(fn ($t) => collect($t)->except('_sort')->all())->values()->all(),
        'alerts'    => $simulatedAlerts->sortBy('_sort')->map(fn ($a) => collect($a)->except('_sort')->all())->values()->all(),
    ];
}

private function calculateTaskDate(Carbon $baseDate, ProtocolTask $task): Carbon
{
    $date = $task->time_of_day === 'after'
        ? $baseDate->copy()->addDays($task->days_offset)
        : $baseDate->copy()->subDays($task->days_offset);

    return $this->applyTime($date, $task->time);
}

private function calculateAlertDate(Carbon $taskDate, ProtocolTaskAlert $alert): Carbon
{
    $date = $alert->time_of_day === 'after'
        ? $taskDate->copy()->addDays($alert->offset_days)
        : $taskDate->copy()->subDays($alert->offset_days);

    return $this->applyTime($date, $alert->time);
}

private function applyTime(Carbon $date, ?string $time): Carbon
{
    if (!$time) {
        return $date->copy()->startOfDay();
    }
    [$h, $m] = explode(':', substr($time, 0, 5));
    return $date->copy()->setTime((int) $h, (int) $m);
}
```
**Dependencias:** `Illuminate\Support\Carbon` (ya disponible en el framework, sin instalar
nada nuevo). No toca `ProtocolRepositoryInterface`.

#### `back/app/Http/Controllers/V1/AdminProtocolController.php`
**Cambio:** agregar método `simulate()`, junto a `replicate()`.
```php
public function simulate(SimulateProtocolRequest $request, string $guid): JsonResponse
{
    try {
        $protocol = $this->protocolService->findByGuidWithTasks($guid);
        if (!$protocol) {
            return $this->makeNotFound('Protocolo no encontrado.');
        }

        $baseDate = Carbon::createFromFormat('Y-m-d', $request->validated('base_date'))->startOfDay();
        $result = $this->protocolService->simulate($protocol, $baseDate);

        return $this->makeSuccess(new ProtocolSimulationResource($result));
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```
**Imports a agregar:** `use App\Http\Requests\Protocols\SimulateProtocolRequest;`,
`use App\Http\Resources\V1\ProtocolSimulationResource;`, `use Illuminate\Support\Carbon;`.

Nota: `findByGuidWithTasks()` ya carga `tasks.alerts` (confirmado en
`ProtocolRepositoryEloquent`/uso existente en `show()`/`update()`), así que `loadMissing` dentro
de `simulate()` es un no-op defensivo, no una query duplicada.

#### `back/routes/api/protocols.php`
**Cambio:** agregar ruta `GET` nueva, mismo permiso que `update`.
```php
Route::get('/{guid}/simulate', [AdminProtocolController::class, 'simulate'])->middleware('can:protocols.update');
```
Ubicarla junto a `GET /{guid}` (antes de `PUT /{guid}`) para agrupar los verbos `GET` del
recurso, evitando colisión con el patrón `{guid}` genérico — Laravel resuelve rutas estáticas
(`/{guid}/simulate`) antes que dinámicas (`/{guid}`) sin importar el orden de registro dentro del
mismo verbo HTTP, pero mantenerlas próximas mejora legibilidad.

### Migrations
Ninguna. Sin columnas ni tablas nuevas — cálculo puro en memoria (DEC-NEG-01).

### Rutas API
| Método | Path | Controller@action | Middleware | Permiso |
|---|---|---|---|---|
| GET | `/v1/admin/protocols/{guid}/simulate` | `AdminProtocolController@simulate` | `auth:sanctum`, `can:protocols.update` | `protocols.update` (DEC-NEG-07) |

### Permisos Spatie
Ninguno nuevo (DEC-NEG-07). No se toca `ProtocolPermissionsSeeder.php`.

### Contrato del endpoint
**Request:** `GET /v1/admin/protocols/{guid}/simulate?base_date=2026-08-15`

**Response 200:** ver shape completo en DEC-01.

**Errores posibles:**
- `422` — `base_date` ausente o con formato inválido → mensaje de `SimulateProtocolRequest`.
- `404` — protocolo no encontrado → `makeNotFound`.
- `403` — usuario sin `protocols.update` → middleware `can:`.

### Tests a generar
En `back/tests/Feature/AdminProtocolControllerTest.php`:
- `simulate()` devuelve 200 con `tasks`/`alerts` calculados correctamente para un protocolo con
  2 tareas (una `before`, una `after`) y alertas con `offset_days` mixtos `before`/`after`.
- Verificar que `computed_date` de una alerta se calcula respecto a `computed_date` de SU tarea,
  no respecto a `base_date` (caso explícito de DEC-NEG-03: tarea `after` + alerta `before` debe
  dar una fecha intermedia entre `base_date` y la fecha de la tarea, no antes de `base_date`).
- `tasks` y `alerts` (lista plana) vienen ordenados cronológicamente ascendente.
- 422 si falta `base_date` o viene con formato `15/08/2026` (formato incorrecto).
- 404 si el `guid` no existe.
- 403 si el usuario no tiene `protocols.update`.
- No se crea/modifica ningún registro en `protocol_tasks`/`protocol_task_alerts` tras llamar al
  endpoint (assert de conteo de filas antes/después, cumpliendo DEC-NEG-01).

En `back/tests/Unit/ProtocolServiceTest.php`:
- `simulate()`: caso `days_offset=0` (mismo día que `base_date`).
- `simulate()`: tarea `before` con `days_offset=5` → fecha = `base_date - 5 días`.
- `simulate()`: alerta `after` con `offset_days=2` sobre una tarea `before` → fecha alerta =
  fecha tarea + 2 días (puede quedar antes, en, o después de `base_date` según los offsets —
  testear el cálculo compuesto explícitamente con números concretos).
- `simulate()`: protocolo sin tareas → devuelve `tasks: []`, `alerts: []` sin error.
- `simulate()` no muta `$protocol` ni sus relaciones cargadas (aplica `->copy()` correctamente,
  no genera efectos colaterales sobre el mismo `Carbon` reutilizado entre tareas).

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/protocols/types/protocol.types.ts` (extender, no crear archivo nuevo)
Ver sección "Archivos a modificar" más abajo — se agregan tipos nuevos ahí.

#### `front/src/modules/protocols/components/ProtocolSimulateDrawer.vue`
**Propósito:** drawer con `a-date-picker` + dos `BaseDataTable` (Tareas, Alertas).
**Props/Emits:**
```typescript
const props = defineProps<{ protocolGuid: string; protocolName: string }>()
const open = defineModel<boolean>('open', { default: false })
```
**Estructura interna:**
```typescript
import dayjs, { type Dayjs } from 'dayjs'
import { useProtocolSimulation } from '../composables/useProtocolSimulation'
import { getRoleLabel } from '@/core/utils/roles'
import type { SimulatedTask, SimulatedAlert } from '../types/protocol.types'

const baseDate = ref<Dayjs | null>(null)
const baseDateParam = computed(() => baseDate.value ? baseDate.value.format('YYYY-MM-DD') : undefined)

const { data, isLoading, isError } = useProtocolSimulation(
  computed(() => props.protocolGuid),
  baseDateParam,
)

function taskRowClassName(record: SimulatedTask): string {
  return record.important ? 'psd-row--important' : ''
}
function alertRowClassName(record: SimulatedAlert): string {
  return record.require_confirmation ? 'psd-row--critical' : ''
}

const taskColumns = [
  { title: 'Tarea', key: 'description', dataIndex: 'description' },
  { title: 'Fecha', key: 'computed_date' },
  { title: 'Hora', key: 'computed_time', dataIndex: 'computed_time' },
]

const alertColumns = [
  { title: 'Tarea asociada', key: 'task_description', dataIndex: 'task_description' },
  { title: 'Mensaje', key: 'message', dataIndex: 'message' },
  { title: 'Destinatarios', key: 'roles' },
  { title: 'Fecha', key: 'computed_date' },
  { title: 'Hora', key: 'computed_time', dataIndex: 'computed_time' },
]
```
**Template (resumen):** `BaseDrawer` con título `` `Simular protocolo — ${protocolName}` ``,
`a-date-picker` arriba (`format="DD/MM/YYYY"`, `v-model:value="baseDate"`), debajo
`a-empty` mientras `!baseDate`, y al elegir fecha: `BaseDataTable` de Tareas con
`:row-class-name="taskRowClassName"`, luego `BaseDataTable` de Alertas con
`:row-class-name="alertRowClassName"` — columna "Destinatarios" renderiza
`record.roles.map(getRoleLabel).join(', ')` vía `bodyCell` custom en `key === 'roles'`. Columna
`computed_date` formatea con `new Date(record.computed_date).toLocaleDateString('es-AR')` (mismo
patrón que `created_at` en `TechniqueProtocolsTab.vue`).
**Estilos:**
```css
:deep(.psd-row--important td) { background: rgba(250, 173, 20, 0.08); border-left: 3px solid #faad14; }
:deep(.psd-row--critical td) { background: rgba(245, 34, 45, 0.08); border-left: 3px solid #f5222d; }
```

#### `front/src/modules/protocols/composables/useProtocolSimulation.ts`
**Propósito:** `useQuery` de lectura, habilitado solo cuando hay `base_date` (evita llamar al
endpoint sin fecha elegida).
```typescript
import { computed, type ComputedRef } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { adminSimulateProtocolApi } from '../api/protocol.api'

export function useProtocolSimulation(guid: ComputedRef<string>, baseDate: ComputedRef<string | undefined>) {
  return useQuery({
    queryKey: computed(() => ['admin-protocol-simulation', guid.value, baseDate.value]),
    queryFn: () => adminSimulateProtocolApi(guid.value, baseDate.value as string),
    enabled: computed(() => !!baseDate.value),
  })
}
```
Sigue el patrón `useQuery` para lecturas de las convenciones frontend — no es `useMutation`
porque `GET` es idempotente y cachea por `[guid, base_date]` (recalcular la misma fecha dos
veces reutiliza cache, correcto para una operación de solo lectura).

### Archivos a modificar

#### `front/src/modules/protocols/types/protocol.types.ts`
**Cambio:** agregar tipos de respuesta de `simulate` al final del archivo.
```typescript
export interface SimulatedAlert {
  guid: string
  task_description: string
  message: string
  roles: TenantRole[]
  require_confirmation: boolean
  computed_date: string
  computed_time: string
}

export interface SimulatedTask {
  guid: string
  description: string
  important: boolean
  computed_date: string
  computed_time: string
  alerts: Omit<SimulatedAlert, 'task_description'>[]
}

export interface ProtocolSimulation {
  protocol: ProtocolTechniqueRef
  base_date: string
  tasks: SimulatedTask[]
  alerts: SimulatedAlert[]
}
```

#### `front/src/modules/protocols/api/protocol.api.ts`
**Cambio:** agregar función nueva, mismo patrón que las existentes.
```typescript
export async function adminSimulateProtocolApi(guid: string, baseDate: string): Promise<ProtocolSimulation> {
  const res = await http.get<ProtocolSimulation>(`/v1/admin/protocols/${guid}/simulate`, {
    params: { base_date: baseDate },
  })
  return res.data
}
```
Import a agregar en el bloque de tipos: `ProtocolSimulation`.

#### `front/src/modules/techniques/components/TechniqueProtocolsTab.vue`
**Cambio:** agregar botón "Simular" en `BaseTableActions`, después de "Editar" y antes de
"Replicar" (frecuencia de uso: simular es una acción de consulta, más frecuente que replicar,
pero menos que editar — ubicación intermedia razonable, no hay regla del ticket sobre el orden
exacto).

**Imports a agregar:**
```typescript
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined, ClockCircleOutlined } from '@ant-design/icons-vue'
import ProtocolSimulateDrawer from '@/modules/protocols/components/ProtocolSimulateDrawer.vue'
```

**State + handler a agregar (nueva sección `--- Simular ---`):**
```typescript
const showSimulateDrawer = ref(false)
const simulatingProtocol = ref<ProtocolListItem | null>(null)

function openSimulateDrawer(protocol: ProtocolListItem) {
  simulatingProtocol.value = protocol
  showSimulateDrawer.value = true
}
```

**Template — botón dentro de `BaseTableActions`:**
```vue
<PermissionGuard permission="protocols.update">
  <BaseButton
    variant="row-action"
    size="small"
    tooltip="Simular fecha"
    @click="openSimulateDrawer(record as ProtocolListItem)"
  >
    <template #icon><ClockCircleOutlined /></template>
  </BaseButton>
</PermissionGuard>
```

**Drawer al final del template (fuera de `BaseDataTable`, junto a `ProtocolFormDrawer`/`ProtocolDeleteModal`):**
```vue
<ProtocolSimulateDrawer
  v-if="showSimulateDrawer && simulatingProtocol"
  v-model:open="showSimulateDrawer"
  :protocol-guid="simulatingProtocol.guid"
  :protocol-name="simulatingProtocol.name"
/>
```

### Tests a generar
Si el proyecto tiene tests de componente para este módulo (confirmar convención existente antes
de escribir):
- `ProtocolSimulateDrawer.vue`: al seleccionar una fecha, dispara `adminSimulateProtocolApi` con
  el `guid` y `base_date` formateado `YYYY-MM-DD`.
- Filas con `important: true` reciben la clase `psd-row--important`; filas con
  `require_confirmation: true` reciben `psd-row--critical`.
- Sin fecha seleccionada, no se llama al endpoint (`enabled: false` del `useQuery`).

## Orden de implementación
1. Backend: crear `SimulateProtocolRequest.php`.
2. Backend: crear `ProtocolSimulationResource.php`.
3. Backend: agregar `simulate()`, `calculateTaskDate()`, `calculateAlertDate()`, `applyTime()` a
   `ProtocolService.php`.
4. Backend: agregar método `simulate()` a `AdminProtocolController.php`.
5. Backend: agregar ruta `GET /{guid}/simulate` en `routes/api/protocols.php`.
6. Backend: escribir/correr tests de `ProtocolServiceTest.php` (cálculo puro) y
   `AdminProtocolControllerTest.php` (contrato HTTP).
7. Frontend: agregar tipos `SimulatedAlert`/`SimulatedTask`/`ProtocolSimulation` en
   `protocol.types.ts`.
8. Frontend: agregar `adminSimulateProtocolApi()` en `protocol.api.ts`.
9. Frontend: crear `useProtocolSimulation.ts`.
10. Frontend: crear `ProtocolSimulateDrawer.vue`.
11. Frontend: agregar botón "Simular" + estado + drawer en `TechniqueProtocolsTab.vue`.
12. Verificación manual end-to-end: simular con un protocolo de 2+ tareas (mix `before`/`after`)
    con alertas mixtas, confirmar fechas calculadas a mano contra la fórmula de DEC-04, orden
    cronológico correcto, y que NO se persiste nada (revisar DB antes/después).

## Riesgos y consideraciones
- **Timezone:** `Carbon::createFromFormat('Y-m-d', ...)` usa el timezone default de la app
  (confirmar en `config/app.php` que sea consistente con el resto del proyecto — no se detectó
  uso de timezones explícitos en `ProtocolTask`/`ProtocolTaskAlert`, así que se asume timezone
  único de servidor, sin lógica multi-timezone nueva).
- **`time` nullable:** tanto `ProtocolTask.time` como `ProtocolTaskAlert.time` no tienen
  constraint de NOT NULL confirmado en el modelo (no se leyó la migración en esta exploración,
  pero `ProtocolTaskResource`/`ProtocolTaskAlertResource` manejan `$this->time` con `?:` como si
  pudiera ser null) — `applyTime()` contempla ese caso con `startOfDay()` como fallback. Si en la
  migración real `time` es NOT NULL, este fallback simplemente nunca se ejecuta (no rompe nada).
- **Multi-tenant:** no aplica — mismo criterio que `replicate()`, el módulo Protocolos
  SuperAdmin opera fuera de scope de tenant.
- **Multi-país:** el cálculo es agnóstico de país (`days_offset`/`offset_days` son enteros
  puros); no hay lógica específica de AR que deba generalizarse.
- **Performance:** un protocolo con N tareas y M alertas por tarea genera O(N×M) iteraciones en
  memoria — volumen esperado bajo (protocolos veterinarios rara vez superan 10-20 tareas), sin
  necesidad de paginación en la respuesta.
- **date_format:Y-m-d vs regla `date` pedida por el ticket:** el ticket dice literalmentente
  "Form Request con `date` requerido" — se interpreta como "una regla de validación de fecha",
  no la regla exacta `date` de Laravel. Se documenta la discrepancia acá por transparencia: se
  eligió `date_format:Y-m-d` (más estricto) en vez de `date` (más laxo) porque el frontend
  siempre envía `YYYY-MM-DD` y aceptar formatos ambiguos no aporta valor, solo riesgo de parseo
  incorrecto en el backend.

## Pendientes / fuera de alcance
- Exportar el cronograma simulado a PDF/Excel (no pedido).
- Guardar/recordar la última fecha simulada por protocolo (no pedido; cada apertura del drawer
  arranca sin fecha).
- Simular sobre un `Program` real (instancia activa con destinatarios concretos) — DEC-NEG-05 del
  ticket es explícito en que esto NO es sobre destinatarios reales, es sobre la plantilla.
- Crear un átomo `BaseDatePicker` reutilizable — el ticket restringe usar `a-date-picker` directo
  salvo necesidad de reutilización futura; si aparece un segundo caso de uso de date-picker simple
  (no rango), extraer el átomo en esa iteración.
