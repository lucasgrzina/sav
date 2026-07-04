# Plan técnico: Alta de cliente desde el detalle de una vet (admin)

## Input procesado

Brief informal del usuario (descripción en chat, 2026-06-11). Plan base de referencia: `.claude/docs/plans/admin-clients.md`.

---

## Resumen ejecutivo

Se agrega el flujo de creación/vinculación de clientes desde la página de detalle de una vet admin (`/admin/vets/:guid`). La ruta nueva es `/admin/vets/:guid/clients/new` y renderiza un stepper de 2 pasos: búsqueda por `tax_id` (con vinculación si ya existe) y formulario de alta si no existe. Al completar cualquier acción exitosa, se navega de vuelta a `/admin/vets/:guid`.

El backend requiere un único método nuevo: `lookupForVet(taxId, vetGuid)` en `ClientService` + un método `lookup` en `AdminClientController` + la ruta correspondiente. Todo lo demás (service, repository, controller, api functions de vinculación y creación) ya está implementado por el plan `admin-clients`.

El frontend requiere: una función API nueva (`adminLookupClientApi`), dos composables nuevos (`useAdminLookupClient`, `useAdminCreateAndLinkClient`), una página nueva (`AdminVetClientCreatePage.vue`), la ruta nueva en `vets.routes.ts`, y el botón "Agregar cliente" en `VetClientsSection.vue`.

---

## Decisiones tomadas

**DEC-01 — Implementación de `lookupForVet` en `ClientService` vs. reutilizar `lookupByTaxId`**
Decisión: Agregar método nuevo `lookupForVet(string $taxId, string $vetGuid): array` en `ClientService`. No reutilizar `lookupByTaxId(taxId, Vet)`.
Justificación: `lookupByTaxId` requiere un objeto `Vet` ya resuelto (con scope de tenant activo, inyectado por middleware). El contexto admin recibe el `vetGuid` como string desde el query param. El nuevo método resuelve internamente la vet por guid antes de llamar a `isLinkedToVet`, lo que mantiene la misma lógica sin contaminar el flujo tenant ni requerir que el controller haga resolución manual.
Alternativa descartada: Modificar `lookupByTaxId` para aceptar `string|Vet` — introduce condicional de tipos en un método ya probado y usado por el flujo tenant.

**DEC-02 — Lookup: endpoint `GET /v1/admin/clients/lookup?tax_id=X&vet_guid=Y` en `AdminClientController`**
Decisión: Agregar método `lookup(Request $request): JsonResponse` en el `AdminClientController` existente. Ruta: `GET /v1/admin/clients/lookup` con parámetros `tax_id` y `vet_guid` en query string. Middleware: `auth:sanctum` + `can:clients.read` (igual que el índice admin).
Justificación: La ruta queda bajo el namespace `/v1/admin/clients/`, que es el controlador natural para operaciones de clientes en contexto admin. El `AdminClientController` ya existe y ya tiene `ClientService` y `VetService` inyectados.
Alternativa descartada: Ruta bajo `/v1/admin/vets/{guid}/clients/lookup` — semánticamente también válida, pero rompe la simetría con el endpoint tenant que vive en `/vets/{vet}/clients/lookup`. Además complica el router de vets con lógica de clientes.

**DEC-03 — Posición de la ruta `/lookup` en `clients.php` para evitar colisión con `/{guid}`**
Decisión: La ruta `GET /lookup` DEBE declararse antes de `GET /{guid}` en el grupo `v1/admin/clients`. Seguir el mismo patrón ya comentado en el archivo de rutas tenant (comentario "IMPORTANTE: /lookup debe ir ANTES de /{guid}").
Justificación: Laravel resuelve rutas en orden de declaración. Si `/{guid}` aparece primero, la cadena literal `"lookup"` sería interpretada como un guid, devolviendo 404 o datos incorrectos.

**DEC-04 — Composable `useAdminLookupClient(vetGuid)` recibe `vetGuid` como argumento, no de la ruta**
Decisión: Firma `useAdminLookupClient(vetGuid: string)`. El `vetGuid` se pasa como argumento explícito (no se extrae de `useRoute()`).
Justificación: La página `AdminVetClientCreatePage.vue` recibe `guid` de la vet como prop de ruta (no como parte del path del lookup). Acoplar el composable a `useRoute()` lo haría no reutilizable y replicaría el defecto de `useLookupClient` que el brief identifica como "no reutilizable directamente".
Alternativa descartada: Aceptar `vetGuid` como `Ref<string>` — innecesario para este caso de uso donde el vetGuid es estático durante toda la sesión en la página.

**DEC-05 — Composable `useAdminCreateAndLinkClient(vetGuid)` como mutación secuencial**
Decisión: Un único composable que llama `adminCreateClientApi` y luego `adminLinkVetToClientApi(newClient.guid, vetGuid)` en secuencia dentro del mismo `mutationFn`. No dos composables separados con coordinación manual en la página.
Justificación: La atomicidad lógica es "crear + vincular" como una sola operación desde el punto de vista del usuario y de la UI. Si el link falla tras crear, se debe mostrar error (no silenciar). Un `mutationFn` único garantiza que `isPending` cubre ambas llamadas, simplifica el manejo de errores en la página y evita estados intermedios visibles.
Alternativa descartada: Llamar `useAdminCreateClient` y `useAdminLinkVet` por separado en la página con `await` encadenado — introduce estado intermedio ("cliente creado pero no vinculado") que la página debería manejar, agregando complejidad innecesaria.

**DEC-06 — Invalidaciones de cache al crear+vincular**
Decisión: Al éxito de `useAdminCreateAndLinkClient`, invalidar: `['admin-clients']`, `['admin-vet-clients', vetGuid]`. No invalidar `['admin-client', guid]` (el detalle del cliente recién creado no fue visitado aún).
Justificación: La lista global de clientes debe actualizarse si el admin vuelve al panel `/admin/clients`. La sección de clientes de la vet (`VetClientsSection`) también debe refrescarse porque se acaba de vincular un cliente nuevo. El detalle individual no tiene queries activas en este flujo.

**DEC-07 — Stepper: implementación con estado local `LookupState` (patrón `ClientLookupForm.vue`)**
Decisión: Replicar el patrón de máquina de estados `LookupState` de `ClientLookupForm.vue`. El estado es un `ref<LookupState>` con variantes: `idle`, `searching`, `found-linkable`, `found-linked`, `not-found`, `creating`, `done`. La página `AdminVetClientCreatePage.vue` gestiona el estado completo.
Justificación: El patrón ya está probado en producción en `ClientLookupForm.vue`. Reutilizarlo garantiza consistencia visual y reduce el riesgo de estados no manejados. La página nueva es la versión admin del mismo flujo.
Alternativa descartada: Implementar el stepper con `<a-steps>` de Ant Design con pasos numerados (1 y 2 visualmente separados). Si bien es más explícito visualmente, el brief describe el comportamiento exacto y el patrón de estados cubre todos los casos borde con menos código de template.

**DEC-08 — Botón en `VetClientsSection.vue`: "Agregar cliente" separado del "Vincular cliente" existente**
Decisión: Agregar un segundo botón "Agregar cliente" junto al botón "Vincular cliente" existente. El nuevo botón navega a `/admin/vets/${props.vetGuid}/clients/new`. Ambos botones quedan guardados con `PermissionGuard permission="clients.create"`.
Justificación: Las dos acciones son semánticamente distintas: "Vincular" busca un cliente que ya existe en el sistema; "Agregar" inicia el flujo de búsqueda+creación para uno que puede no existir. Mantenerlos separados evita ambigüedad en la UI y no rompe el flujo de vinculación existente.

---

## Cambios en BACKEND

### Archivos a crear

No se crean archivos nuevos en el backend. El `AdminClientController`, `ClientService`, `ClientRepositoryEloquent` e interfaces ya existen. Solo se agregan métodos.

---

### Archivos a modificar

#### `back/app/Services/ClientService.php`
**Cambio:** Agregar método `lookupForVet(string $taxId, string $vetGuid): array`.

```php
/**
 * Busca un client por tax_id y verifica si ya está vinculado a la vet
 * identificada por guid. Diseñado para el contexto admin (sin Vet resuelto por middleware).
 *
 * @return array{ found: bool, client: ?Client, already_linked: bool }
 */
public function lookupForVet(string $taxId, string $vetGuid): array
{
    $client = $this->clientRepository->findByTaxId($taxId);

    if (!$client) {
        return ['found' => false, 'client' => null, 'already_linked' => false];
    }

    // Resolver la vet por guid para verificar el vínculo
    $vet = \App\Models\Vet::where('guid', $vetGuid)->first();

    // Si la vet no existe, el cliente "existe pero no vinculado" es la respuesta más segura.
    // El controller valida la existencia de la vet antes de llamar este método.
    $alreadyLinked = $vet
        ? $this->clientRepository->isLinkedToVet($client, $vet)
        : false;

    return [
        'found'          => true,
        'client'         => $client,
        'already_linked' => $alreadyLinked,
    ];
}
```

**Dependencias:** `ClientRepositoryInterface::findByTaxId` y `::isLinkedToVet` (ambos ya existen). El método accede a `Vet::where()` directamente en lugar de pasar por `VetService` para evitar inyectar `VetService` en `ClientService` (que introduciría dependencia circular potencial si `VetService` algún día inyecta `ClientService`). Si el dev prefiere usar `VetService::findByGuid()`, es igualmente válido — solo debe inyectarse en el constructor.

---

#### `back/app/Http/Controllers/V1/AdminClientController.php`
**Cambio:** Agregar método `lookup(Request $request): JsonResponse`.

Agregar `use Illuminate\Http\Request;` al bloque de imports (verificar si ya existe — el controller actual no lo importa porque no usa `Request` directamente).

```php
/**
 * Busca un client por tax_id y verifica vinculación con la vet indicada.
 * Endpoint: GET /v1/admin/clients/lookup?tax_id=X&vet_guid=Y
 * Sin scope de vet (contexto admin).
 */
public function lookup(Request $request): JsonResponse
{
    try {
        $taxId   = $request->query('tax_id');
        $vetGuid = $request->query('vet_guid');

        if (empty($taxId)) {
            return $this->makeError(
                ['tax_id' => ['El parámetro tax_id es requerido.']],
                'Parámetros inválidos.',
                422,
            );
        }

        if (empty($vetGuid)) {
            return $this->makeError(
                ['vet_guid' => ['El parámetro vet_guid es requerido.']],
                'Parámetros inválidos.',
                422,
            );
        }

        // Validar existencia de la vet antes de buscar
        $vet = $this->vetService->findByGuid($vetGuid);
        if (!$vet) {
            return $this->makeNotFound('Veterinaria no encontrada.');
        }

        $result = $this->clientService->lookupForVet($taxId, $vetGuid);

        if (!$result['found']) {
            return $this->makeSuccess(['found' => false, 'client' => null, 'already_linked' => false]);
        }

        $result['client']->load(['country', 'documentType']);

        return $this->makeSuccess([
            'found'          => true,
            'already_linked' => $result['already_linked'],
            'client'         => new ClientResource($result['client']),
        ]);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Nota sobre imports:** `VetService` ya está inyectado en el constructor. `ClientResource` ya está importado. Solo agregar `use Illuminate\Http\Request;`.

---

#### `back/routes/api/clients.php`
**Cambio:** Agregar ruta `GET /lookup` ANTES de `GET /{guid}` en el grupo `v1/admin/clients`.

**Antes (resumido):**
```php
Route::prefix('v1/admin/clients')->middleware('auth:sanctum')->group(function () {
    Route::get('/',       [AdminClientController::class, 'index'])->middleware('can:clients.read');
    Route::post('/',      [AdminClientController::class, 'store'])->middleware('can:clients.create');
    Route::get('/{guid}', [AdminClientController::class, 'show'])->middleware('can:clients.read');
    // ...
});
```

**Después (resumido):**
```php
Route::prefix('v1/admin/clients')->middleware('auth:sanctum')->group(function () {
    Route::get('/',       [AdminClientController::class, 'index'])->middleware('can:clients.read');
    Route::post('/',      [AdminClientController::class, 'store'])->middleware('can:clients.create');

    // IMPORTANTE: /lookup debe ir ANTES de /{guid} para evitar colisión de rutas
    Route::get('/lookup', [AdminClientController::class, 'lookup'])->middleware('can:clients.read');

    Route::get('/{guid}', [AdminClientController::class, 'show'])->middleware('can:clients.read');
    Route::put('/{guid}', [AdminClientController::class, 'update'])->middleware('can:clients.update');

    Route::post('/{clientGuid}/vets',             [AdminClientController::class, 'linkVet'])->middleware('can:clients.create');
    Route::delete('/{clientGuid}/vets/{vetGuid}', [AdminClientController::class, 'unlinkVet'])->middleware('can:clients.delete');
});
```

---

### Migrations

No se requieren migraciones.

---

### Rutas API

#### Nueva ruta en `back/routes/api/clients.php`

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| GET | `/v1/admin/clients/lookup` | `AdminClientController@lookup` | `auth:sanctum`, `can:clients.read` |

Parámetros query: `tax_id` (required), `vet_guid` (required).

---

### Permisos Spatie

No se crean permisos nuevos. Se reutiliza `clients.read` que ya existe.

---

### Contrato del endpoint

#### GET /v1/admin/clients/lookup?tax_id=X&vet_guid=Y

**Request:** query params `tax_id: string (required)`, `vet_guid: string (required, guid de vet)`

**Response 200 — no encontrado:**
```json
{
  "success": true,
  "data": {
    "found": false,
    "client": null,
    "already_linked": false
  }
}
```

**Response 200 — encontrado, no vinculado:**
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": false,
    "client": {
      "guid": "uuid",
      "name": "Establecimiento El Ombú",
      "tax_id": "30-12345678-9",
      "country": { "guid": "uuid", "name": "Argentina", "iso_code": "AR", "phone_prefix": "+54" },
      "document_type": { "guid": "uuid", "name": "CUIT" }
    }
  }
}
```

**Response 200 — encontrado y ya vinculado:**
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": true,
    "client": { "guid": "uuid", "name": "...", "tax_id": "..." }
  }
}
```

**Errores posibles:**

| HTTP | Código de error | Cuándo |
|------|-----------------|--------|
| 422 | `tax_id required` | Parámetro `tax_id` ausente |
| 422 | `vet_guid required` | Parámetro `vet_guid` ausente |
| 404 | `not_found` | La vet con ese `vet_guid` no existe |

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — `AdminClientController@lookup`:**
- `tax_id` ausente → 422 con error en campo `tax_id`.
- `vet_guid` ausente → 422 con error en campo `vet_guid`.
- `vet_guid` de vet inexistente → 404.
- `tax_id` de cliente inexistente → 200 con `found: false, client: null`.
- `tax_id` de cliente existente, no vinculado a la vet → 200 con `found: true, already_linked: false, client: {...}`.
- `tax_id` de cliente existente, ya vinculado a la vet → 200 con `found: true, already_linked: true, client: {...}`.
- Usuario sin permiso `clients.read` → 403.

**Unit tests — `ClientService::lookupForVet`:**
- Cliente no existe → `found: false`.
- Cliente existe, vet no existe (guid inválido) → `found: true, already_linked: false`.
- Cliente existe, no vinculado → `found: true, already_linked: false`.
- Cliente existe, ya vinculado → `found: true, already_linked: true`.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/clients/api/admin-clients.api.ts`
**Cambio:** Agregar función `adminLookupClientApi` al archivo existente (no crear archivo nuevo).

Ver sección "Archivos a modificar" abajo.

---

#### `front/src/modules/clients/composables/admin/useAdminLookupClient.ts`
**Propósito:** Versión admin del lookup de cliente por `tax_id`. Recibe `vetGuid` como argumento (no de la ruta).

```typescript
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { adminLookupClientApi } from '../../api/admin-clients.api'

export function useAdminLookupClient(vetGuid: string) {
  const taxId   = ref<string>('')
  const enabled = ref(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['admin-client-lookup', vetGuid, taxId],
    queryFn:  () => adminLookupClientApi(taxId.value, vetGuid),
    enabled:  computed(() => enabled.value && Boolean(taxId.value)),
    staleTime: 0,
    retry: false,
  })

  function search(newTaxId: string): void {
    taxId.value   = newTaxId
    enabled.value = true
  }

  function reset(): void {
    taxId.value   = ''
    enabled.value = false
    queryClient.removeQueries({ queryKey: ['admin-client-lookup', vetGuid] })
  }

  return { ...query, taxId, search, reset }
}
```

**Diferencias clave respecto a `useLookupClient`:**
- No usa `useRoute()`.
- `vetGuid` viene como argumento.
- Llama `adminLookupClientApi` (endpoint admin) en lugar de `lookupClientApi`.
- Query key usa prefijo `'admin-client-lookup'` para no colisionar con el lookup tenant.

---

#### `front/src/modules/clients/composables/admin/useAdminCreateAndLinkClient.ts`
**Propósito:** Mutación que crea un cliente y lo vincula a la vet en secuencia.

```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminCreateClientApi, adminLinkVetToClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientCreatePayload, ClientItem } from '../../types/client.types'

export function useAdminCreateAndLinkClient(vetGuid: string) {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: async (payload: ClientCreatePayload): Promise<ClientItem> => {
      // Paso 1: crear el cliente (sin vet)
      const newClient = await adminCreateClientApi(payload)
      // Paso 2: vincular a la vet. Si falla aquí, el cliente existe pero no vinculado.
      // El error se propaga y onError lo informa al usuario.
      await adminLinkVetToClientApi(newClient.guid, vetGuid)
      return newClient
    },
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-clients'] })
      queryClient.invalidateQueries({ queryKey: ['admin-vet-clients', vetGuid] })
      success('Cliente creado y vinculado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el cliente.'
      if (apiError.message) error(apiError.message)
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

**Nota sobre fallo en el link:** Si `adminCreateClientApi` tiene éxito pero `adminLinkVetToClientApi` falla (ej: 422 por vínculo duplicado, lo cual no debería ocurrir en este flujo), el cliente queda creado pero no vinculado. En ese caso el `onError` informa el error y el usuario puede intentar nuevamente desde el paso 1 (el lookup lo encontrará y ofrecerá vincularlo). No se necesita lógica de rollback.

---

#### `front/src/modules/vets/pages/AdminVetClientCreatePage.vue`
**Propósito:** Página con el stepper de búsqueda y creación de cliente desde el detalle de una vet.

Props: `guid: string` (el guid de la vet, recibido de la ruta via `props: true`).

**Estructura del script:**
```typescript
// Props
const props = defineProps<{ guid: string }>()

// Router para navegación de regreso
const router = useRouter()

// Tipos de estado (mismo patrón que ClientLookupForm.vue)
type LookupState =
  | { status: 'idle' }
  | { status: 'searching' }
  | { status: 'found-linkable';  client: ClientItem }
  | { status: 'found-linked';    client: ClientItem }
  | { status: 'not-found';       taxId: string }
  | { status: 'creating' }
  | { status: 'done' }

const taxIdInput = ref('')
const state      = ref<LookupState>({ status: 'idle' })

// Composables
const { data: lookupData, isLoading: isSearching, isError: isSearchError, search, reset: resetLookup }
  = useAdminLookupClient(props.guid)

const { mutateAsync: linkAsync, isPending: isLinking, generalError: linkError }
  = useAdminLinkVetToClient()   // ver nota (*) abajo

const { mutateAsync: createAsync, isPending: isCreating, fieldErrors, generalError: createError }
  = useAdminCreateAndLinkClient(props.guid)

// Watch al resultado del lookup (idéntico a ClientLookupForm.vue)
watch([lookupData, isSearching, isSearchError], ([data, loading, hasError]) => {
  // ... mismo patrón
})

// Handlers
function handleSearch(): void { ... }
function resetSearch(): void  { ... }

async function handleLink(clientGuid: string): Promise<void> {
  // Llama adminLinkVetToClientApi(clientGuid, props.guid)
  // Al éxito: router.push(`/admin/vets/${props.guid}`)
}

async function handleCreate(values: ClientCreateForm): Promise<void> {
  state.value = { status: 'creating' }
  await createAsync(values, {
    onSuccess: () => router.push(`/admin/vets/${props.guid}`),
    onError:   () => { state.value = { status: 'not-found', taxId: taxIdInput.value } },
  })
}
```

(*) **Nota sobre `handleLink`:** Para vincular un cliente existente encontrado en el lookup, se llama directamente a `adminLinkVetToClientApi` (ya existe en el api file). No hay un composable dedicado para este caso específico (vincular `clientGuid` → `vetGuid`). El composable `useAdminLinkVet(clientGuid)` existente vincula un `vetGuid` a un `clientGuid`, que es el caso inverso (desde el detalle del cliente). Para este flujo (desde el detalle de la vet), se puede reutilizar ese composable inicializándolo con un `clientGuid` reactivo, O simplemente llamar a `adminLinkVetToClientApi` directamente en el handler con `useMutation` inline. La opción más simple y directa es la mutación inline en la página, dado que es un solo uso.

**Estructura del template:**

```html
<template>
  <div class="avcc-root">
    <!-- Header con breadcrumb -->
    <div class="avcc-header">
      <button class="avcc-back" @click="router.push(`/admin/vets/${props.guid}`)">
        <ArrowLeftOutlined /> Volver a la veterinaria
      </button>
      <h2 class="avcc-title">Agregar cliente</h2>
    </div>

    <!-- Sección de búsqueda — siempre visible -->
    <div class="avcc-search">
      <a-input ... />
      <a-button ... @click="handleSearch">Buscar</a-button>
    </div>

    <!-- Estados: searching / found-linkable / found-linked / not-found+creating -->
    <!-- Mismo patrón de template que ClientLookupForm.vue -->

    <!-- FOUND-LINKABLE: mostrar datos + botón "Vincular a esta veterinaria" -->
    <!-- FOUND-LINKED: aviso + "Ver cliente" (→ /admin/clients/:clientGuid) + "Buscar otro" -->
    <!-- NOT-FOUND: aviso + ClientForm mode="create" pre-poblado con tax_id + botón "Volver al paso 1" -->
  </div>
</template>
```

**Estilos:** Mismo approach que `ClientLookupForm.vue` con prefijo de clase `avcc-`.

---

### Archivos a modificar

#### `front/src/modules/clients/api/admin-clients.api.ts`
**Cambio:** Agregar función `adminLookupClientApi` al final de la sección "Admin Clients".

```typescript
// Tipo de respuesta del lookup
export interface AdminLookupClientResponse {
  found: boolean
  already_linked: boolean
  client: ClientItem | null
}

export async function adminLookupClientApi(
  taxId: string,
  vetGuid: string,
): Promise<AdminLookupClientResponse> {
  const res = await http.get<AdminLookupClientResponse>('/v1/admin/clients/lookup', {
    params: { tax_id: taxId, vet_guid: vetGuid },
  })
  return res.data
}
```

El tipo `AdminLookupClientResponse` puede definirse en el mismo archivo o en `client.types.ts`. Se define en el api file para no contaminar los tipos del dominio con un tipo específico de respuesta de endpoint.

---

#### `front/src/modules/vets/router/vets.routes.ts`
**Cambio:** Agregar la ruta `/admin/vets/:guid/clients/new` ANTES de `/:guid/edit` para evitar que `:guid` capture el segmento `clients`.

**Antes:**
```typescript
export const vetsRoutes: RouteRecordRaw[] = [
  { path: '/admin/vets',           name: 'vets-list',   ... },
  { path: '/admin/vets/new',       name: 'vets-create', ... },
  { path: '/admin/vets/:guid',     name: 'vets-detail', props: true, ... },
  { path: '/admin/vets/:guid/edit',name: 'vets-edit',   props: true, ... },
]
```

**Después:**
```typescript
export const vetsRoutes: RouteRecordRaw[] = [
  { path: '/admin/vets',                    name: 'vets-list',           ... },
  { path: '/admin/vets/new',                name: 'vets-create',         ... },
  { path: '/admin/vets/:guid',              name: 'vets-detail',   props: true, ... },
  { path: '/admin/vets/:guid/edit',         name: 'vets-edit',     props: true, ... },
  {
    path: '/admin/vets/:guid/clients/new',
    name: 'vets-client-create',
    component: () => import('@/modules/vets/pages/AdminVetClientCreatePage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Agregar cliente a veterinaria' },
  },
]
```

**Nota sobre orden de rutas en Vue Router:** Vue Router 4 usa un sistema de scoring que prioriza rutas más específicas sobre las dinámicas. La ruta `/admin/vets/:guid/clients/new` tiene segmentos literales adicionales (`clients`, `new`) que la hacen más específica que `/:guid`. No es estrictamente necesario ponerla antes, pero por consistencia con el patrón del proyecto (ver comentario "IMPORTANTE" en `clients.php`) y para mayor claridad, se agrega al final del array donde está conceptualmente relacionada.

---

#### `front/src/modules/vets/components/VetClientsSection.vue`
**Cambio:** Agregar botón "Agregar cliente" junto al botón "Vincular cliente" existente.

**Antes (toolbar):**
```html
<div class="vcs-toolbar">
  <a-input-search ... />
  <PermissionGuard permission="clients.create">
    <BaseButton @click="isModalOpen = true">
      <template #icon><PlusOutlined /></template>
      Vincular cliente
    </BaseButton>
  </PermissionGuard>
</div>
```

**Después (toolbar):**
```html
<div class="vcs-toolbar">
  <a-input-search ... />
  <div class="vcs-actions">
    <PermissionGuard permission="clients.create">
      <BaseButton
        variant="secondary"
        @click="router.push(`/admin/vets/${props.vetGuid}/clients/new`)"
      >
        <template #icon><UserAddOutlined /></template>
        Agregar cliente
      </BaseButton>
    </PermissionGuard>
    <PermissionGuard permission="clients.create">
      <BaseButton @click="isModalOpen = true">
        <template #icon><PlusOutlined /></template>
        Vincular cliente
      </BaseButton>
    </PermissionGuard>
  </div>
</div>
```

**Cambios en `<script setup>`:**
- Importar `UserAddOutlined` de `@ant-design/icons-vue`.
- El `router` ya está importado en el componente.

**Cambio en `<style scoped>`:**
```css
.vcs-actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
```

---

### Tipos TypeScript

No se crean tipos nuevos en `client.types.ts`. El tipo `AdminLookupClientResponse` se define inline en `admin-clients.api.ts` (ver arriba) por ser un tipo específico de respuesta HTTP, no un tipo de dominio.

---

## Orden de implementación

### Backend

1. Agregar método `lookupForVet(string $taxId, string $vetGuid): array` en `back/app/Services/ClientService.php`. Agregar `use Illuminate\Http\Request;` si no está importado.
2. Agregar método `lookup(Request $request): JsonResponse` en `back/app/Http/Controllers/V1/AdminClientController.php`. Verificar que `use Illuminate\Http\Request;` esté en los imports del controller.
3. Modificar `back/routes/api/clients.php`: agregar `Route::get('/lookup', ...)` inmediatamente después de `Route::post('/', ...)` y ANTES de `Route::get('/{guid}', ...)`.
4. Probar el endpoint manualmente (o con Artisan tinker) que: sin parámetros devuelve 422, vet inexistente devuelve 404, cliente no encontrado devuelve `found: false`, cliente encontrado no vinculado devuelve `found: true, already_linked: false`, cliente ya vinculado devuelve `already_linked: true`.

### Frontend

5. Agregar `adminLookupClientApi` y el tipo `AdminLookupClientResponse` en `front/src/modules/clients/api/admin-clients.api.ts`.
6. Crear `front/src/modules/clients/composables/admin/useAdminLookupClient.ts`.
7. Crear `front/src/modules/clients/composables/admin/useAdminCreateAndLinkClient.ts`.
8. Crear `front/src/modules/vets/pages/AdminVetClientCreatePage.vue` con el stepper completo (búsqueda + vinculación + creación).
9. Modificar `front/src/modules/vets/router/vets.routes.ts`: agregar la ruta `vets-client-create`.
10. Modificar `front/src/modules/vets/components/VetClientsSection.vue`: agregar botón "Agregar cliente" + importar `UserAddOutlined`.
11. Verificar flujo completo end-to-end:
    - Ir a `/admin/vets/:guid` → sección "Clientes vinculados" → botón "Agregar cliente".
    - Buscar un `tax_id` que no existe → ver aviso "No encontrado" → completar formulario → crear+vincular → redirigir a `/admin/vets/:guid` → cliente aparece en la lista.
    - Buscar un `tax_id` que existe y no está vinculado → ver datos → "Vincular a esta veterinaria" → redirigir a `/admin/vets/:guid` → cliente aparece.
    - Buscar un `tax_id` que ya está vinculado → ver aviso → botón "Ver cliente" navega a `/admin/clients/:guid` → botón "Buscar otro" resetea el estado.

---

## Riesgos y consideraciones

**R-01 — Fallo entre `adminCreateClientApi` y `adminLinkVetToClientApi` en `useAdminCreateAndLinkClient`**
Si `adminCreateClientApi` tiene éxito pero `adminLinkVetToClientApi` falla (ej: network error, 500 del servidor), el cliente queda huérfano (sin vínculo con la vet). No hay mecanismo de rollback. La probabilidad es baja porque ambas llamadas suceden en milisegundos bajo las mismas condiciones de red. Si ocurre, el usuario puede buscarlo por `tax_id` en el paso 1 y el sistema le ofrecerá vincularlo (`found-linkable`). Documentar en el mensaje de error: "Cliente creado. Error al vincular — podés buscarlo por CUIT para vincularlo".

**R-02 — Orden de ruta `/lookup` vs `/{guid}` en `clients.php`**
Si al agregar la ruta `GET /lookup` se coloca DESPUÉS de `GET /{guid}`, la cadena literal `"lookup"` será capturada como un guid y el controller devolverá 404 ("Cliente no encontrado"). Es el error de implementación más probable en el backend. El dev debe verificar el orden visualmente en el archivo antes de hacer el commit.

**R-03 — `lookupForVet` consulta `Vet::where('guid', $vetGuid)` directamente en `ClientService`**
El método accede al model `Vet` directamente sin pasar por `VetService`. Esto es aceptable para una query de lectura simple (`where guid = X`), pero introduce una leve violación del patrón de capas (Service → Repository). Si el equipo prefiere estricta separación, la alternativa es inyectar `VetRepositoryInterface` en `ClientService`. Para esta iteración, el acceso directo al modelo es pragmático y consistente con cómo `resolveIds()` accede a `Country` y `DocumentType` en el mismo servicio.

**R-04 — Multi-tenant: el endpoint `/v1/admin/clients/lookup` no tiene scope de vet**
Por diseño, el admin puede buscar cualquier cliente global. La validación de `vet_guid` es solo para determinar `already_linked`. No hay exposición cross-tenant inadvertida porque el campo `already_linked` no revela datos privados de esa vet — solo confirma la existencia del vínculo en la tabla pivot `client_vet`. Los datos del cliente (`name`, `tax_id`) son datos del objeto `Client` que son globales por diseño del modelo.

**R-05 — `ClientForm.vue` en modo create: campos `country_guid` y `document_type_guid` son obligatorios**
La validación de `clientCreateSchema` requiere `country_guid` y `document_type_guid`. El pre-poblado de `tax_id` es automático, pero el usuario debe seleccionar país y tipo de documento manualmente. Esto es correcto. Solo asegurarse de que el `ClientForm` reciba `initialValues: { tax_id: state.taxId }` correctamente en la página nueva (idéntico a como lo hace `ClientLookupForm.vue` en línea 218 del template tenant).

**R-06 — Vue Router 4: colisión de rutas `/admin/vets/:guid/clients/new` con `/:guid`**
Vue Router 4 usa un sistema de scoring. La ruta `/admin/vets/:guid/clients/new` tiene segmentos estáticos adicionales y NO colisionará con `/admin/vets/:guid`. Sin embargo, `/admin/vets/:guid/edit` tiene la misma estructura (`:guid` + segmento estático). Vue Router resolverá correctamente. No es necesario reordenar, pero se documenta para que el dev no lo malinterprete.

---

## Pendientes / fuera de alcance

**P-01 — Mensaje de error diferenciado cuando falla solo el link (R-01)**
El mensaje de error de `useAdminCreateAndLinkClient` en el `onError` actual es genérico. Para distinguir si falló la creación o el link requeriría capturar el error en un punto intermedio del `mutationFn`. Queda para una iteración futura si se reporta el caso borde en producción.

**P-02 — Paginación en la sección de búsqueda del stepper**
El lookup busca por `tax_id` exacto: retorna un solo resultado o ninguno. Si en el futuro se quiere buscar por nombre parcial (búsqueda difusa), el endpoint y el composable deberían refactorizarse para retornar una lista paginada. Fuera de alcance de este plan.

**P-03 — Confirmación antes de vincular en estado `found-linkable`**
El flujo actual vincula directamente al hacer click en "Vincular a esta veterinaria" sin un modal de confirmación. Aceptable para MVP. En el futuro puede agregarse un `useConfirm` previo a `handleLink`.
