# Plan técnico: Panel de administración global de clientes

## Input procesado

Brief informal del usuario (descripción en chat, 2026-06-11). No hay spec ni ticket previo.

---

## Resumen ejecutivo

Se agrega un panel `/admin/clients` para el super admin que permite gestionar clientes de forma global (sin scope de vet), con CRUD completo y gestión bidireccional del vínculo `client_vet`. El backend requiere un nuevo controlador `AdminClientController` que reutiliza el `ClientService` ya existente, más tres métodos nuevos en el service/repository para paginar globalmente y gestionar vínculos desde el lado admin. El frontend crea un nuevo sub-módulo de páginas admin dentro del módulo `clients/` existente (sin duplicar tipos ni api base), agrega rutas bajo `/admin/clients`, agrega la entrada al menú en `AppMenu.vue`, y extiende `VetDetailPage.vue` con una sección de clientes vinculados. No se crean nuevas migraciones: la tabla `client_vet` ya soporta el modelo de relación requerido.

---

## Decisiones tomadas

**DEC-01 — Nuevo controlador vs. extender ClientController**
Decisión: Crear `AdminClientController` separado, siguiendo el patrón exacto de `AdminVetController`.
Justificación: `ClientController` tiene dependencia en `$request->attributes->get('current_vet')` (inyectado por `vet.tenant` middleware) en cada método. Mezclarlo con rutas sin ese middleware generaría código defensivo frágil y viola el principio de responsabilidad única. La separación es limpia y coherente con el patrón ya establecido.
Alternativa descartada: Agregar un flag `$adminMode` al `ClientController` existente — introduce ramificación condicional en todos los métodos y aumenta la deuda técnica.

**DEC-02 — Nuevo método en ClientService vs. nuevo AdminClientService**
Decisión: Extender `ClientService` con los métodos nuevos (`paginateAll`, `linkToVetByGuid`, `detachFromVetByGuid`). No crear un nuevo service.
Justificación: La lógica de negocio de clientes es la misma; el contexto admin solo omite el scope de vet en la consulta. Crear un servicio paralelo duplicaría lógica como `resolveIds()` y `linkToVet()`. Los métodos nuevos son adiciones no disruptivas.
Alternativa descartada: `AdminClientService` separado — solo movería código sin valor agregado.

**DEC-03 — Endpoint de vínculo/desvínculo desde admin**
Decisión: El `AdminClientController` expone dos acciones de vínculo que reciben el `guid` de la vet en el body/ruta (no del middleware):
- `POST /v1/admin/clients/{clientGuid}/vets` con `{ vet_guid }` en body → vincular.
- `DELETE /v1/admin/clients/{clientGuid}/vets/{vetGuid}` → desvincular.
Justificación: El admin opera desde el contexto del cliente (no de la vet), lo que es semánticamente correcto. Recibir `vet_guid` en el body del POST es consistente con cómo `StoreLinkRequest` recibe datos de vínculo en el contexto tenant.
Alternativa descartada: Reutilizar el endpoint tenant `/vets/{vet}/clients/{guid}/link` — requeriría que el admin tenga un contexto de vet activo, lo cual no aplica.

**DEC-04 — Endpoint "clientes de una vet" desde admin (para VetDetailPage)**
Decisión: `GET /v1/admin/vets/{vetGuid}/clients` — nuevo endpoint en `AdminVetController`. Retorna la lista paginada de clientes vinculados a esa vet. Desvincular usa `DELETE /v1/admin/clients/{clientGuid}/vets/{vetGuid}` (DEC-03).
Justificación: Es más natural que la vet "tenga" clientes; el endpoint queda bajo el namespace de vets-admin. Para desvincular se reutiliza el endpoint admin de clientes (bidireccional por diseño).
Alternativa descartada: Endpoint separado `GET /v1/admin/clients?vet_guid=X` con filtro — complica la lógica de paginación y mezcla contextos.

**DEC-05 — Sub-módulo de páginas admin en frontend**
Decisión: Agregar páginas admin dentro del módulo `clients/` existente bajo `clients/pages/admin/` y composables admin bajo `clients/composables/admin/`. No crear un módulo `admin-clients/` separado.
Justificación: Los tipos (`ClientItem`, `ClientDetail`, `ClientListParams`) y el `ClientResource` del backend ya son los mismos para ambos contextos. Duplicar el módulo solo para las páginas admin viola DRY. Las funciones API admin se agregan en `clients.api.ts` bajo un comentario de sección (patrón ya presente en el archivo).
Alternativa descartada: Módulo `admin-clients/` paralelo — duplicaría tipos, validators y componentes sin diferencia funcional real.

**DEC-06 — Sección "Clientes vinculados" en VetDetailPage**
Decisión: Agregar un nuevo tab `a-tab-pane` al componente `VetDetailPage.vue` que renderiza un componente nuevo `VetClientsSection.vue` (en `vets/components/`). El componente usa composables de `admin` del módulo `clients`.
Justificación: `VetDetailPage.vue` ya usa `a-tabs` para separar secciones. Agregar un tab más es la modificación mínima y consistente con el diseño existente.
Alternativa descartada: Tarjeta separada fuera de los tabs — rompe la consistencia visual de la página.

**DEC-07 — Lookup de vet para vincular desde detalle de cliente admin**
Decisión: Para vincular una vet desde el detalle de un cliente admin, se usa el endpoint existente `GET /v1/admin/vets` con parámetro `search` (ya paginado). El modal de vinculación hace una búsqueda por nombre/CUIT.
Justificación: El endpoint `AdminVetController@index` ya acepta `search` y devuelve listado paginado. No se necesita un endpoint de lookup dedicado.
Alternativa descartada: Endpoint `/v1/admin/vets/lookup?name=X` nuevo — innecesario, `index` ya cubre el caso.

**DEC-08 — Permisos: reutilizar `clients.read/create/update/delete`**
Decisión: El panel admin reutiliza los permisos `clients.*` ya existentes en el seeder. No se crean permisos nuevos del tipo `admin-clients.*`.
Justificación: El permiso ya existe y el super-admin tiene todos los permisos. Crear permisos duplicados para el contexto admin agrega complejidad sin valor funcional — el super-admin es la única audiencia de este panel.
Alternativa descartada: Permisos `clients.admin.*` separados — granularidad innecesaria para el caso de uso actual.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Http/Controllers/V1/AdminClientController.php`
**Propósito:** Controlador admin para gestión global de clientes y vínculos client-vet, sin middleware `vet.tenant`.
**Firma principal:**
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\IndexClientRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Requests\Admin\Clients\AdminLinkVetRequest;
use App\Http\Resources\V1\ClientResource;
use App\Services\ClientService;
use App\Services\VetService;
use Illuminate\Http\JsonResponse;

class AdminClientController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private VetService    $vetService,
    ) {}

    public function index(IndexClientRequest $request): JsonResponse
    {
        // $this->clientService->paginateAll($filters, $perPage)
        // return $this->makeSuccessPagination($paginator, ClientResource::class)
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        // $this->clientService->createWithoutVet($request->validated())
        // NO vincula a ninguna vet. Cliente huérfano es válido.
        // return $this->makeSuccess(new ClientResource($client), 'Cliente creado correctamente.', 201)
    }

    public function show(string $guid): JsonResponse
    {
        // $client = $this->clientService->findByGuid($guid)  // ya existe en ClientService
        // $client->load(['country', 'documentType', 'contacts', 'establishments', 'vets'])
        // return $this->makeSuccess(new ClientResource($client))
    }

    public function update(UpdateClientRequest $request, string $guid): JsonResponse
    {
        // $client = $this->clientService->findByGuid($guid)
        // $client = $this->clientService->update($client, $request->validated())  // ya existe
        // return $this->makeSuccess(new ClientResource($client), 'Cliente actualizado correctamente.')
    }

    /**
     * Vincula una vet al cliente. Recibe { vet_guid } en el body.
     */
    public function linkVet(AdminLinkVetRequest $request, string $clientGuid): JsonResponse
    {
        // $client = $this->clientService->findByGuid($clientGuid)
        // $vet    = $this->vetService->findByGuid($request->validated()['vet_guid'])
        // Validar que vet exista → 404 si no
        // $this->clientService->linkToVet($client, $vet)  // ya existe, lanza RuntimeException si ya vinculado
        // return $this->makeSuccess(null, 'Veterinaria vinculada correctamente.', 201)
    }

    /**
     * Desvincula una vet del cliente.
     */
    public function unlinkVet(string $clientGuid, string $vetGuid): JsonResponse
    {
        // $client = $this->clientService->findByGuid($clientGuid)
        // $vet    = $this->vetService->findByGuid($vetGuid)
        // Validar existencia de ambos → 404 si no
        // $this->clientService->detach($client, $vet)  // ya existe en ClientService
        // return $this->makeSuccess(null, 'Veterinaria desvinculada correctamente.')
    }
}
```
**Dependencias inyectadas:** `ClientService`, `VetService`

---

#### `back/app/Http/Requests/Admin/Clients/AdminLinkVetRequest.php`
**Propósito:** Valida el body del endpoint de vinculación admin (`POST /v1/admin/clients/{guid}/vets`).
**Firma principal:**
```php
namespace App\Http\Requests\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class AdminLinkVetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vet_guid' => ['required', 'string', 'exists:vets,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'vet_guid.required' => 'El guid de la veterinaria es obligatorio.',
            'vet_guid.exists'   => 'La veterinaria seleccionada no existe.',
        ];
    }
}
```

---

### Archivos a modificar

#### `back/app/Services/ClientService.php`
**Cambio:** Agregar tres métodos nuevos para el contexto admin.

**Método 1 — `paginateAll`:**
```php
public function paginateAll(array $filters, int $perPage): LengthAwarePaginator
{
    return $this->clientRepository->paginateAll($filters, $perPage);
}
```

**Método 2 — `createWithoutVet`:**
```php
public function createWithoutVet(array $data): Client
{
    return DB::transaction(function () use ($data) {
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);
        $data = $this->resolveIds($data);
        // Crear client sin vincularlo a ninguna vet (no llama createForVet)
        $client = $this->clientRepository->create($data);
        foreach ($contacts as $contactData) {
            $this->contactService->create($client, $contactData);
        }
        return $client;
    });
}
```
Nota: `$this->clientRepository->create($data)` hereda de `BaseRepositoryEloquent` — ya existe y crea el modelo sin pivot.

**Método 3 — ya existe `detach(Client, Vet)`, se reutiliza sin cambio.**

No hay método 3 nuevo: `linkToVet` y `detach` ya existen en `ClientService`.

---

#### `back/app/Contracts/Repositories/ClientRepositoryInterface.php`
**Cambio:** Agregar firma del método nuevo `paginateAll`.
```php
/** Pagina todos los clients del sistema sin filtro de vet. */
public function paginateAll(array $filters, int $perPage): LengthAwarePaginator;
```

---

#### `back/app/Repositories/ClientRepositoryEloquent.php`
**Cambio:** Implementar `paginateAll`.
```php
public function paginateAll(array $filters, int $perPage): LengthAwarePaginator
{
    $query = $this->newQuery()->with(['country', 'documentType']);

    if (!empty($filters['search'])) {
        $query->where(function ($q) use ($filters) {
            $q->where('name',   'like', '%' . $filters['search'] . '%')
              ->orWhere('tax_id', 'like', '%' . $filters['search'] . '%');
        });
    }

    return $query->latest('created_at')->paginate($perPage);
}
```

---

#### `back/app/Http/Controllers/V1/AdminVetController.php`
**Cambio:** Agregar método `clients()` para listar los clientes vinculados a una vet desde el panel admin.
```php
public function clients(Request $request, string $guid): JsonResponse
{
    try {
        $vet = $this->vetService->findByGuid($guid);
        if (!$vet) {
            return $this->makeNotFound('Veterinaria no encontrada.');
        }
        $perPage   = $request->integer('per_page', 15);
        $filters   = $request->only(['search']);
        // Reutiliza paginateForVet ya existente en ClientService
        $paginator = $this->clientService->paginate($vet, $filters, $perPage);
        return $this->makeSuccessPagination($paginator, ClientResource::class);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```
**Cambio adicional en constructor:** Inyectar `ClientService`.
```php
public function __construct(
    private VetService    $vetService,
    private ClientService $clientService,  // AGREGAR
) {}
```
Se requiere importar `ClientService` y `ClientResource`.

---

#### `back/app/Http/Resources/V1/ClientResource.php`
**Cambio:** Agregar carga condicional de `vets` (la lista de vets vinculadas se necesita en el detalle admin).
```php
'vets' => VetResource::collection($this->whenLoaded('vets')),
```
Esta línea se agrega al array de `toArray()`. La relación `Client::vets()` ya existe en el modelo.

---

### Migrations

No se requieren migraciones nuevas. La tabla `client_vet` ya existe y soporta el modelo de relación. La creación de clientes sin vet es válida por diseño (no hay constraint NOT NULL en `client_vet` hacia un client recién creado — la tabla es pivot opcional).

---

### Rutas API

#### En `back/routes/api/clients.php` — agregar grupo admin al final del archivo:

```php
use App\Http\Controllers\V1\AdminClientController;

// --- Panel SuperAdmin ---
Route::prefix('v1/admin/clients')->middleware('auth:sanctum')->group(function () {
    Route::get('/',          [AdminClientController::class, 'index'])->middleware('can:clients.read');
    Route::post('/',         [AdminClientController::class, 'store'])->middleware('can:clients.create');
    Route::get('/{guid}',    [AdminClientController::class, 'show'])->middleware('can:clients.read');
    Route::put('/{guid}',    [AdminClientController::class, 'update'])->middleware('can:clients.update');

    // Gestión de vínculo client-vet desde el lado admin (sin middleware vet.tenant)
    Route::post('/{clientGuid}/vets',              [AdminClientController::class, 'linkVet'])->middleware('can:clients.create');
    Route::delete('/{clientGuid}/vets/{vetGuid}',  [AdminClientController::class, 'unlinkVet'])->middleware('can:clients.delete');
});
```

#### En `back/routes/api/vets.php` — agregar ruta dentro del grupo `v1/admin/vets`:

```php
// Dentro del grupo Route::prefix('v1/admin/vets')->middleware('auth:sanctum')
Route::get('/{guid}/clients', [AdminVetController::class, 'clients'])->middleware('can:clients.read');
```

---

### Permisos Spatie

No se crean permisos nuevos. Los permisos `clients.read`, `clients.create`, `clients.update`, `clients.delete` ya existen en `PermissionSeeder` y el rol `super-admin` ya los tiene todos por `syncPermissions(Permission::all())`.

---

### Contrato de endpoints

#### GET /v1/admin/clients
Request params: `search?: string`, `page?: int`, `per_page?: int`
Response 200:
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "uuid",
        "name": "Establecimiento Don Julio",
        "tax_id": "20-12345678-9",
        "address": null,
        "city": null,
        "state": null,
        "zip_code": null,
        "country": { "guid": "uuid", "name": "Argentina", "iso_code": "AR", "phone_prefix": "+54" },
        "document_type": { "guid": "uuid", "name": "CUIT" },
        "contacts": [],
        "created_at": "2025-01-15T10:30:00.000Z"
      }
    ],
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

#### POST /v1/admin/clients
Request body:
```json
{
  "name": "string (required, max:150)",
  "country_guid": "uuid (required, exists:countries)",
  "document_type_guid": "uuid (required, exists:document_types)",
  "tax_id": "string (required, max:50, validado por regex del doc_type)",
  "address": "string|null (opcional)",
  "city": "string|null (opcional)",
  "state": "string|null (opcional)",
  "zip_code": "string|null (opcional)",
  "contacts": "array|null (opcional, max:10)"
}
```
Response 201: `{ "success": true, "data": ClientResource, "message": "Cliente creado correctamente." }`

#### GET /v1/admin/clients/{guid}
Response 200: `{ "success": true, "data": ClientResource }` — incluye `vets` (lista de vets vinculadas) cargada con `whenLoaded`.
El controller llama `$client->load(['country', 'documentType', 'contacts', 'establishments', 'vets'])`.

El campo `vets` en el resource es un array de `VetResource` con los campos básicos (guid, name, slug, is_active).

#### PUT /v1/admin/clients/{guid}
Request body: igual que `UpdateClientRequest` (todos opcionales via `sometimes`).
Response 200: `{ "success": true, "data": ClientResource, "message": "Cliente actualizado correctamente." }`

#### POST /v1/admin/clients/{clientGuid}/vets
Request body:
```json
{ "vet_guid": "uuid (required, exists:vets,guid)" }
```
Response 201: `{ "success": true, "data": null, "message": "Veterinaria vinculada correctamente." }`
Errores:
| HTTP | Cuándo |
|------|--------|
| 404 | Client o vet no encontrados |
| 422 | Ya están vinculados (`RuntimeException` de `ClientService::linkToVet`) |

#### DELETE /v1/admin/clients/{clientGuid}/vets/{vetGuid}
Response 200: `{ "success": true, "data": null, "message": "Veterinaria desvinculada correctamente." }`
Errores:
| HTTP | Cuándo |
|------|--------|
| 404 | Client o vet no encontrados |

#### GET /v1/admin/vets/{guid}/clients
Request params: `search?: string`, `page?: int`, `per_page?: int`
Response 200: mismo shape de paginación que GET /v1/admin/clients, sin campo `vets` en cada item.

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — AdminClientController:**
- `index`: lista clientes sin filtro → 200 con paginación; con `search` → filtra correctamente.
- `index`: usuario sin permiso `clients.read` → 403.
- `store`: datos válidos sin vet → 201, cliente existe en DB sin vínculo en `client_vet`.
- `store`: datos inválidos (sin `name`) → 422 con `errors`.
- `show`: guid existente → 200 con relaciones `vets` cargadas; guid inexistente → 404.
- `update`: datos parciales → 200, solo campos enviados se modifican.
- `linkVet`: vet_guid válido no vinculada → 201, fila creada en `client_vet`.
- `linkVet`: vet ya vinculada → 422 con mensaje.
- `linkVet`: vet_guid inexistente → 404.
- `unlinkVet`: vínculo existente → 200, fila eliminada de `client_vet`.
- `unlinkVet`: vínculo inexistente → 200 (detach silencioso, Eloquent no lanza error si no existe).

**Feature tests — AdminVetController@clients:**
- Lista clientes de una vet → 200 paginado; filtro `search` funciona.
- Vet sin clientes → 200 con `data: []`, `total: 0`.

**Unit tests — ClientService:**
- `paginateAll`: retorna paginator sin scope de vet.
- `createWithoutVet`: crea el client, llama `contactService->create` por cada contacto, no vincula a ninguna vet.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/clients/api/admin-clients.api.ts`
**Propósito:** Funciones HTTP para el panel admin de clientes (base URL `/v1/admin/clients` y `/v1/admin/vets/{guid}/clients`).
```typescript
import { http } from '@/core/api/http'
import type {
  ClientItem,
  ClientDetail,
  ClientListParams,
  ClientListResponse,
  ClientCreatePayload,
  ClientUpdatePayload,
} from '../types/client.types'
import type { VetItem } from '@/modules/vets/types/vet.types'

// --- Admin Clients ---

export async function adminListClientsApi(
  params: ClientListParams,
  signal?: AbortSignal,
): Promise<ClientListResponse> {
  const res = await http.get<ClientListResponse>('/v1/admin/clients', { params, signal })
  return res.data
}

export async function adminGetClientApi(guid: string): Promise<ClientDetail & { vets: VetItem[] }> {
  const res = await http.get<ClientDetail & { vets: VetItem[] }>(`/v1/admin/clients/${guid}`)
  return res.data
}

export async function adminCreateClientApi(payload: ClientCreatePayload): Promise<ClientItem> {
  const res = await http.post<ClientItem>('/v1/admin/clients', payload)
  return res.data
}

export async function adminUpdateClientApi(
  guid: string,
  payload: ClientUpdatePayload,
): Promise<ClientItem> {
  const res = await http.put<ClientItem>(`/v1/admin/clients/${guid}`, payload)
  return res.data
}

export async function adminLinkVetToClientApi(clientGuid: string, vetGuid: string): Promise<void> {
  await http.post(`/v1/admin/clients/${clientGuid}/vets`, { vet_guid: vetGuid })
}

export async function adminUnlinkVetFromClientApi(
  clientGuid: string,
  vetGuid: string,
): Promise<void> {
  await http.delete(`/v1/admin/clients/${clientGuid}/vets/${vetGuid}`)
}

// --- Clients de una Vet (desde detalle de vet) ---

export async function adminListClientsByVetApi(
  vetGuid: string,
  params: ClientListParams,
  signal?: AbortSignal,
): Promise<ClientListResponse> {
  const res = await http.get<ClientListResponse>(`/v1/admin/vets/${vetGuid}/clients`, {
    params,
    signal,
  })
  return res.data
}
```

---

#### `front/src/modules/clients/composables/admin/useAdminClients.ts`
**Propósito:** Query para listar clientes globalmente (panel admin).
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListClientsApi } from '../../api/admin-clients.api'
import type { ClientFilters } from '../../types/client.types'

export function useAdminClients(filters: Ref<ClientFilters> | ClientFilters = {}) {
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['admin-clients', filtersRef],
    queryFn: ({ signal }) => adminListClientsApi(filtersRef.value, signal),
    staleTime: 1000 * 30,
  })
}
```

---

#### `front/src/modules/clients/composables/admin/useAdminClient.ts`
**Propósito:** Query para detalle de un cliente (panel admin), incluye vets vinculadas.
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminGetClientApi } from '../../api/admin-clients.api'

export function useAdminClient(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['admin-client', guidValue],
    queryFn: () => adminGetClientApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
```

---

#### `front/src/modules/clients/composables/admin/useAdminCreateClient.ts`
**Propósito:** Mutación para crear cliente sin vet desde el panel admin.
```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminCreateClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientCreatePayload } from '../../types/client.types'

export function useAdminCreateClient() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: ClientCreatePayload) => adminCreateClientApi(payload),
    onMutate: () => { fieldErrors.value = null; generalError.value = null },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-clients'] })
      success('Cliente creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el cliente.'
      if (apiError.message) error('Error al crear el cliente')
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

---

#### `front/src/modules/clients/composables/admin/useAdminUpdateClient.ts`
**Propósito:** Mutación para editar cliente desde panel admin.
Firma análoga a `useAdminCreateClient`, con `mutationFn: ({ guid, payload }) => adminUpdateClientApi(guid, payload)`. Al éxito invalida `['admin-clients']` y `['admin-client', guid]`.

---

#### `front/src/modules/clients/composables/admin/useAdminLinkVet.ts`
**Propósito:** Mutación para vincular una vet a un cliente desde el panel admin.
```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminLinkVetToClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'

export function useAdminLinkVet(clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (vetGuid: string) => adminLinkVetToClientApi(clientGuid, vetGuid),
    onMutate: () => { generalError.value = null },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-client', clientGuid] })
      success('Veterinaria vinculada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      generalError.value = apiError.message ?? 'Error al vincular la veterinaria.'
      error(generalError.value)
    },
  })

  return { ...mutation, generalError }
}
```

---

#### `front/src/modules/clients/composables/admin/useAdminUnlinkVet.ts`
**Propósito:** Mutación con confirmación para desvincular vet de cliente (desde detalle de cliente admin).
Firma análoga a `useUnlinkClient` existente, pero llama `adminUnlinkVetFromClientApi` y al éxito invalida `['admin-client', clientGuid]`.

---

#### `front/src/modules/clients/composables/admin/useAdminClientsByVet.ts`
**Propósito:** Query para listar clientes de una vet específica (para la sección en VetDetailPage).
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListClientsByVetApi } from '../../api/admin-clients.api'
import type { ClientFilters } from '../../types/client.types'

export function useAdminClientsByVet(
  vetGuid: Ref<string> | string,
  filters: Ref<ClientFilters> | ClientFilters = {},
) {
  const guidValue  = computed(() => toValue(vetGuid))
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['admin-vet-clients', guidValue, filtersRef],
    queryFn: ({ signal }) => adminListClientsByVetApi(guidValue.value, filtersRef.value, signal),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: 1000 * 30,
  })
}
```

---

#### `front/src/modules/clients/composables/admin/useAdminUnlinkClientFromVet.ts`
**Propósito:** Mutación con confirmación para desvincular un cliente de una vet (desde VetDetailPage). Reutiliza `adminUnlinkVetFromClientApi(clientGuid, vetGuid)` — mismo endpoint, roles invertidos. Al éxito invalida `['admin-vet-clients', vetGuid]`.

---

#### `front/src/modules/clients/pages/admin/AdminClientListPage.vue`
**Propósito:** Lista global de clientes del sistema con buscador y paginación.
Estructura análoga a `VetsListPage.vue`:
- Header con título "Clientes" y botón "Nuevo cliente" (guarded con `clients.create`).
- Buscador de texto.
- `AdminClientsTable` (componente nuevo, ver abajo).
- `BasePagination`.
- Navega a `/admin/clients/new` y `/admin/clients/:guid`.

---

#### `front/src/modules/clients/pages/admin/AdminClientCreatePage.vue`
**Propósito:** Formulario para crear cliente sin vet.
Reutiliza el componente `ClientForm.vue` existente. El submit llama `useAdminCreateClient`. Al crear exitosamente, navega a `/admin/clients/:guid` del cliente creado.

---

#### `front/src/modules/clients/pages/admin/AdminClientDetailPage.vue`
**Propósito:** Detalle de un cliente con sección "Veterinarias vinculadas".
Props: `guid: string`.
Estructura:
- Header con nombre, tax_id, botón editar y breadcrumb "Volver a clientes".
- Tarjetas de datos básicos (datos fiscales, dirección) — reutiliza estructura de `ClientDetailPage.vue`.
- Tabs: "Establecimientos" | "Contactos" | "Owners" | "Veterinarias vinculadas".
  - Los primeros 3 tabs reutilizan `EstablishmentsSection`, `ContactsSection`, `OwnersSection` pero **no** pueden usarse directamente porque dependen de `vetSlug` en la ruta. Se debe crear una variante o adaptar esas secciones para aceptar la URL base como prop (ver DEC-09 en Pendientes).
  - El tab "Veterinarias vinculadas" renderiza `ClientVetsSection.vue`.

---

#### `front/src/modules/clients/pages/admin/AdminClientEditPage.vue`
**Propósito:** Formulario para editar datos básicos de un cliente (sin vet).
Props: `guid: string`.
Reutiliza `ClientForm.vue` en modo edición. El submit llama `useAdminUpdateClient`. Al actualizar navega de vuelta a `/admin/clients/:guid`.

---

#### `front/src/modules/clients/components/admin/AdminClientsTable.vue`
**Propósito:** Tabla de clientes para el panel admin. Similar a `ClientsTable.vue` pero con navegación a `/admin/clients/:guid` en lugar de `/vets/:vetSlug/clients/:guid`. Sin columna de acción "Desvincular" (no aplica en contexto global).
Columnas: Nombre, CUIT/Doc., País, Alta, Acciones (Ver, Editar).

---

#### `front/src/modules/clients/components/admin/ClientVetsSection.vue`
**Propósito:** Sección "Veterinarias vinculadas" en `AdminClientDetailPage`. Muestra la lista de vets del cliente con opción de desvincular, y un botón/modal para buscar y vincular una vet existente.
Lógica:
- `data: client.vets` (viene del `useAdminClient` ya cargado).
- Botón "Vincular veterinaria" → abre `LinkVetModal`.
- Cada fila tiene botón "Desvincular" que llama `useAdminUnlinkVet`.
- Navegar a detalle de vet: `router.push('/admin/vets/:vetGuid')`.

---

#### `front/src/modules/clients/components/admin/LinkVetModal.vue`
**Propósito:** Modal para buscar y vincular una vet al cliente desde el panel admin.
Props: `clientGuid: string`, emite `linked` al éxito.
Lógica:
- Input de búsqueda → llama `listVetsApi(params)` de `vets.api.ts` con debounce.
- Muestra resultados en lista con nombre, slug, estado.
- Al seleccionar una vet → llama `useAdminLinkVet(clientGuid).mutateAsync(vet.guid)`.
- Al éxito: emite evento `linked` y cierra el modal.

---

#### `front/src/modules/vets/components/VetClientsSection.vue`
**Propósito:** Sección "Clientes vinculados" para `VetDetailPage.vue` (contexto admin).
Props: `vetGuid: string`.
Lógica:
- Usa `useAdminClientsByVet(props.vetGuid)`.
- Lista de clientes con columnas: Nombre, Tax ID, Alta, Acciones.
- Acción "Ver" navega a `/admin/clients/:guid`.
- Acción "Desvincular" llama `useAdminUnlinkClientFromVet`.
- Botón "Vincular cliente existente" → abre `LinkClientModal` (componente nuevo en `vets/components/`).

---

#### `front/src/modules/vets/components/LinkClientModal.vue`
**Propósito:** Modal para buscar un cliente global y vincularlo a la vet actual (desde VetDetailPage).
Props: `vetGuid: string`, emite `linked`.
Lógica:
- Input de búsqueda → llama `adminListClientsApi({ search })` con debounce.
- Muestra resultados.
- Al seleccionar → llama `adminLinkVetToClientApi(clientGuid, vetGuid)` (misma API, roles invertidos).
- Al éxito: invalida `['admin-vet-clients', vetGuid]` y emite `linked`.

---

#### `front/src/modules/clients/router/admin-clients.routes.ts`
**Propósito:** Rutas del panel admin de clientes.
```typescript
import type { RouteRecordRaw } from 'vue-router'

export const adminClientsRoutes: RouteRecordRaw[] = [
  {
    path: '/admin/clients',
    name: 'admin-clients-list',
    component: () => import('@/modules/clients/pages/admin/AdminClientListPage.vue'),
    meta: { requiresAuth: true, title: 'Clientes (Admin)' },
  },
  {
    // /new DEBE ir antes que /:guid
    path: '/admin/clients/new',
    name: 'admin-clients-create',
    component: () => import('@/modules/clients/pages/admin/AdminClientCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Nuevo cliente' },
  },
  {
    path: '/admin/clients/:guid',
    name: 'admin-clients-detail',
    component: () => import('@/modules/clients/pages/admin/AdminClientDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle del cliente' },
  },
  {
    path: '/admin/clients/:guid/edit',
    name: 'admin-clients-edit',
    component: () => import('@/modules/clients/pages/admin/AdminClientEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar cliente' },
  },
]
```

---

### Archivos a modificar

#### `front/src/router/index.ts`
**Cambio:** Importar y registrar `adminClientsRoutes`.
```typescript
import { adminClientsRoutes } from '@/modules/clients/router/admin-clients.routes'
// ... dentro del array children del layout autenticado:
...adminClientsRoutes,
```

---

#### `front/src/components/layouts/partials/AppMenu.vue`
**Cambio:** Agregar entrada "Clientes" al array `adminNavItems`, guarded con `clients.read`.
```typescript
import { UserOutlined } from '@ant-design/icons-vue' // o el ícono apropiado
// Usar UsergroupAddOutlined o TeamOutlined si ya está importado para otra cosa,
// o importar IdcardOutlined que semanticamente aplica a "clientes/campo"

const adminNavItems = [
  { path: '/admin/vets',            label: 'Veterinarias',   icon: MedicineBoxOutlined, permission: 'vets.read'              },
  { path: '/admin/clients',         label: 'Clientes',       icon: IdcardOutlined,      permission: 'clients.read'           }, // AGREGAR
  { path: '/admin/system-settings', label: 'Config. global', icon: ControlOutlined,     permission: 'system-settings.manage' },
]
```
Importar `IdcardOutlined` de `@ant-design/icons-vue`.

---

#### `front/src/modules/vets/pages/VetDetailPage.vue`
**Cambio:** Agregar import de `VetClientsSection` y un tab al final de la grilla de tarjetas.
```vue
<script setup>
// Agregar import:
import VetClientsSection from '../components/VetClientsSection.vue'
</script>

<template>
  <!-- ... código existente ... -->
  <!-- Después de vd-grid, agregar: -->
  <a-tabs class="vd-tabs">
    <a-tab-pane key="clients" tab="Clientes vinculados">
      <VetClientsSection :vet-guid="props.guid" />
    </a-tab-pane>
  </a-tabs>
</template>
```
Agregar clase `.vd-tabs { margin-top: 24px; }` en el `<style scoped>`.

---

### Tipos TypeScript

#### `front/src/modules/clients/types/client.types.ts`
**Cambio:** Extender `ClientDetail` para incluir `vets` cuando se carga desde admin.
```typescript
import type { VetItem } from '@/modules/vets/types/vet.types'

// Modificar la interfaz existente:
export interface ClientDetail extends ClientItem {
  establishments: EstablishmentItem[]
  vets?: VetItem[]  // solo presente en contexto admin (whenLoaded)
}
```

---

## Orden de implementación

### Backend (hacer primero)

1. Agregar método `paginateAll` a `ClientRepositoryInterface` (`back/app/Contracts/Repositories/ClientRepositoryInterface.php`).
2. Implementar `paginateAll` en `ClientRepositoryEloquent` (`back/app/Repositories/ClientRepositoryEloquent.php`).
3. Agregar métodos `paginateAll` y `createWithoutVet` a `ClientService` (`back/app/Services/ClientService.php`).
4. Crear `AdminLinkVetRequest` (`back/app/Http/Requests/Admin/Clients/AdminLinkVetRequest.php`).
5. Crear `AdminClientController` (`back/app/Http/Controllers/V1/AdminClientController.php`).
6. Agregar método `clients()` y dependencia `ClientService` a `AdminVetController` (`back/app/Http/Controllers/V1/AdminVetController.php`).
7. Agregar campo `vets` en `ClientResource` (`back/app/Http/Resources/V1/ClientResource.php`).
8. Registrar rutas admin en `back/routes/api/clients.php` y `back/routes/api/vets.php`.
9. Correr tests de backend para verificar que los endpoints tenant existentes no se rompen.

### Frontend (después del backend)

10. Crear `front/src/modules/clients/api/admin-clients.api.ts`.
11. Crear composables admin uno a uno en `front/src/modules/clients/composables/admin/`:
    - `useAdminClients.ts`
    - `useAdminClient.ts`
    - `useAdminCreateClient.ts`
    - `useAdminUpdateClient.ts`
    - `useAdminLinkVet.ts`
    - `useAdminUnlinkVet.ts`
    - `useAdminClientsByVet.ts`
    - `useAdminUnlinkClientFromVet.ts`
12. Extender `ClientDetail` en `client.types.ts` con `vets?: VetItem[]`.
13. Crear `AdminClientsTable.vue` (`front/src/modules/clients/components/admin/`).
14. Crear `ClientVetsSection.vue` y `LinkVetModal.vue` (`front/src/modules/clients/components/admin/`).
15. Crear páginas admin: `AdminClientListPage.vue`, `AdminClientCreatePage.vue`, `AdminClientDetailPage.vue`, `AdminClientEditPage.vue` (`front/src/modules/clients/pages/admin/`).
16. Crear `admin-clients.routes.ts` y registrar en `router/index.ts`.
17. Agregar entrada "Clientes" en `AppMenu.vue` (importar ícono + agregar a `adminNavItems`).
18. Crear `VetClientsSection.vue` y `LinkClientModal.vue` en `front/src/modules/vets/components/`.
19. Modificar `VetDetailPage.vue` para agregar el tab de clientes vinculados.
20. Verificar flujos end-to-end: lista → detalle → vincular vet → desvincular vet → editar.

---

## Riesgos y consideraciones

**R-01 — Reutilización de secciones de detalle con dependencia en `vetSlug`**
`EstablishmentsSection.vue`, `ContactsSection.vue` y `OwnersSection.vue` usan internamente `useRoute()` para extraer `vetSlug`. En `AdminClientDetailPage` no hay `vetSlug` en la ruta. Las opciones son: (a) pasar la URL base como prop a cada sección, (b) crear variantes admin de estas secciones, o (c) no mostrar esas secciones en el panel admin (solo mostrar el tab "Veterinarias vinculadas"). Se recomienda (c) como solución mínima de primera iteración y dejar (a) para una siguiente iteración. Esto debe documentarse como pendiente.

**R-02 — Multi-tenant: AdminClientController no tiene scope de vet (correcto por diseño)**
La ausencia del middleware `vet.tenant` en las rutas admin es intencional. Sin embargo, si en el futuro se agrega lógica de "vet propietaria" o datos privados por vet en el modelo `Client`, el endpoint admin podría exponer datos cross-tenant. El modelo actual no tiene datos específicos de vet en la tabla `clients` (todo lo sensible está en `client_vet` o en tablas relacionadas), por lo que no hay riesgo inmediato. Documentar para revisión al escalar.

**R-03 — `createWithoutVet` crea clients sin ningún vínculo**
Un cliente sin vet existe en la tabla `clients` pero ningún veterinario lo ve en su panel. Esto es correcto por requerimiento. El riesgo es que acumulen "clientes huérfanos" que confundan al admin. En una iteración futura puede agregarse un filtro `sin_vet=true` en el índice admin.

**R-04 — `AdminVetController` recibe `ClientService` inyectado nuevo**
La inyección de `ClientService` en `AdminVetController` acopla levemente el dominio de vets con el de clients a nivel de controlador. Alternativa: crear un método `getClientsForVet(Vet $vet)` en `VetService` que delegue al `ClientService`. Para esta iteración, inyección directa en el controller es aceptable y más simple. Evaluar refactor si `AdminVetController` crece en responsabilidades.

**R-05 — Nombre del permiso: `clients.read` vs. convención de la memoria `clients.lectura`**
El archivo de memoria (`database_schema.md`) documenta la convención como `{modulo}.lectura`. Sin embargo, el código real (`PermissionSeeder.php`) usa `clients.read` (en inglés). El código gana. Los permisos del sistema SAV usan inglés para el namespace de clientes y vets (`clients.read`, `vets.read`), y español para otros módulos (`users.lectura`). Esta inconsistencia existe en el código actual y no se corrige en este plan.

**R-06 — Desvincular desde VetDetailPage usa el endpoint de AdminClient**
`DELETE /v1/admin/clients/{clientGuid}/vets/{vetGuid}` — los parámetros están en orden "cliente primero, vet después". Esto es consistente con la URL del recurso (`/admin/clients/:guid/vets`), pero requiere que `LinkClientModal.vue` y `VetClientsSection.vue` pasen el `clientGuid` y `vetGuid` en el orden correcto. Error de parámetros invertidos es el riesgo de implementación más probable en esta área.

**R-07 — `ClientForm.vue` puede tener dependencia en `vetSlug`**
No leí `ClientForm.vue` en detalle. Antes de reutilizarlo en `AdminClientCreatePage`, verificar que no use `useRoute()` internamente para extraer el contexto de vet. Si lo hace, deberá refactorizarse para aceptar el submit-handler como prop (patrón usado en otros formularios del proyecto).

---

## Pendientes / fuera de alcance

**P-01 — Secciones de detalle (Establecimientos, Contactos, Owners) en contexto admin**
Las secciones `EstablishmentsSection`, `ContactsSection`, `OwnersSection` dependen del `vetSlug` en la ruta y no se pueden usar directamente en `/admin/clients/:guid`. Refactorizarlas para aceptar `baseUrl` como prop queda para una iteración futura. En esta iteración, el detalle de cliente admin no muestra esas secciones (o las muestra como read-only leyendo directamente de `ClientDetail`).

**P-02 — Filtro "clientes sin vet" en el panel admin**
Útil para gestionar clientes huérfanos, pero no fue solicitado. Agregar como filtro `unlinked=true` en el backend requiere un scope en `paginateAll`. Dejar para siguiente iteración.

**P-03 — Soft confirmation al desvincular vet desde admin**
En el contexto tenant, desvincular muestra un confirm dialog que advierte "el cliente seguirá en el sistema". En el contexto admin, la advertencia debería ser más prominente ("los protocolos activos de esta vet para este cliente pueden verse afectados"). Dejar para siguiente iteración una vez que los módulos de protocolos existan.

**P-04 — Creación de cliente + vínculo inmediato desde la sección de VetDetailPage**
El requerimiento menciona "buscar/vincular existentes" desde el detalle de vet. Crear un cliente nuevo desde ese contexto (sin pasar por el panel de clientes admin) queda fuera de alcance de esta iteración por complejidad adicional de UX (modal de creación completo).

**P-05 — `ClientForm.vue` reutilización en contexto admin**
Requiere verificación (ver R-07). Si la forma tiene acoplamiento interno con el contexto de vet, el refactor de ese componente se planifica por separado.
