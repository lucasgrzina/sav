# Plan técnico: Capa HTTP Multi-Tenancy SAV v2

## Input procesado
Brief informal del usuario (texto libre en el chat) — iteración 2 sobre la capa core de multi-tenancy. La iteración 1 está documentada en `multitenancy-core-plan.md` y ya fue ejecutada completamente.

## Resumen ejecutivo
Se implementa la capa HTTP completa (controllers, FormRequests, Resources, rutas) sobre la capa de datos y dominio ya existente de multi-tenancy. Incluye: endpoints públicos de países/tipos de documento, CRUD de vets para superadmin (con flujos de validación y suspensión), vista y edición de la propia vet para el panel tenant, gestión de miembros del tenant (UserProfiles), y CRUD de contactos polimórfico para vet y para miembros. Se amplían VetService, VetRepositoryInterface y UserProfileRepositoryInterface con los métodos necesarios para soportar estos endpoints. No se crean nuevas migraciones ni modelos.

---

## Decisiones tomadas

### DEC-01 — Naming de permisos: inglés con punto, consistente con el proyecto
**Decisión:** Los permisos nuevos siguen el patrón existente en `PermissionSeeder`: `{modulo}.{accion}` en inglés (`vets.read`, `vets.create`, `vets.update`, `vets.delete`, `vets.validate`). El brief del usuario propone nombres en español (`vets.lectura`, `vets.alta`, etc.) pero el código real usa inglés.
**Justificación:** El código real es la fuente de verdad. El `PermissionSeeder` usa `users.read`, `users.create`, `users.update`, `users.delete`, `roles.read`, etc. Cambiar a español rompería la consistencia del sistema y complicaría el code de autorización. Se adopta inglés.
**Alternativa descartada:** Nombres en español tal como propone el brief. Descartado porque contradice el patrón vigente en el código real.

### DEC-02 — Validación de tax_id: en el FormRequest, no en el Service
**Decisión:** La validación del `tax_id` contra el `validation_regex` del `DocumentType` se hace en `StoreVetRequest` y `UpdateVetRequest` mediante una regla `Rule::custom` (closure o clase `Rule`) que carga el `DocumentType` por su guid y aplica `preg_match()`.
**Justificación:** La validación de formato de input es responsabilidad del FormRequest. El Service no debe rechazar datos — valida reglas de negocio de estado, no de formato. Además, el `document_type_guid` es un campo del request; el FormRequest ya tiene acceso a él para hacer el check de existencia, con lo cual la regla adicional de regex no agrega dependencias nuevas.
**Alternativa descartada:** Validar en el Service y lanzar excepción. Descartado porque el controller debería catchear esa excepción y mapearla a 422, lo cual es un anti-patrón dado que el FormRequest ya provee el mecanismo estándar de Laravel para esto.

### DEC-03 — Controller de contactos: un controller con contexto polimórfico, no dos controllers separados
**Decisión:** Un solo `ContactController` que recibe el `contactable` (vet o profile) como modelo ya resuelto, pasado por un método `resolveContactable()` privado. Las rutas duales (bajo `/vets/{vet}/contacts` y bajo `/vets/{vet}/members/{profile}/contacts`) apuntan al mismo controller; la diferencia está en cuál argumento de ruta está presente.
**Justificación:** La lógica de create/update/delete de contactos es idéntica independientemente del contactable. Duplicar el controller introduce duplicación de código y divergencia futura. El método `resolveContactable()` abstrae la resolución del contexto. Patrón usado en proyectos Laravel polimórficos.
**Alternativa descartada:** `VetContactController` y `MemberContactController` separados. Descartado por duplicación y mantenimiento.

### DEC-04 — Scope de tenant en ContactController: verificación explícita del perfil
**Decisión:** Cuando el contactable es un `UserProfile`, el controller verifica que `$profile->authenticatable_type === 'vet'` y `$profile->authenticatable_id === $currentVet->id` antes de operar. Esta verificación va en `resolveContactable()`.
**Justificación:** El middleware `vet.tenant` resuelve la vet del slug y verifica que el usuario autenticado pertenece a esa vet. Sin embargo, no verifica que el `{profile}` de la URL pertenezca a la misma vet. Si se omite esta verificación, un usuario del tenant A podría operar sobre contactos de un UserProfile del tenant B pasando su guid en la URL — violación de seguridad inter-tenant crítica.
**Alternativa descartada:** No verificar (confiar solo en el middleware). Descartado porque el middleware no cubre el parámetro `{profile}` de la URL.

### DEC-05 — Listado de vets para admin: paginación con filtros validados
**Decisión:** `GET /v1/admin/vets` acepta filtros `validated` (boolean), `suspended` (boolean), `search` (string), `per_page` (int, default 15, max 100). Los filtros van en `IndexVetRequest`. El método del repositorio `paginate(array $filters, int $perPage)` se agrega a `VetRepositoryInterface`.
**Justificación:** Patrón consistente con `UserController::index()` que acepta `filters` y `perPage`. La paginación es obligatoria cuando el listado puede ser grande (regla del brief). Validar los filtros en el FormRequest previene inyecciones y valores inválidos.
**Alternativa descartada:** Filtros directamente desde `$request->all()` sin FormRequest. Descartado por inconsistencia con el patrón del proyecto.

### DEC-06 — validate/suspend/unsuspend: métodos separados en VetService, no flags en update
**Decisión:** Tres métodos específicos en `VetService`: `validate(Vet $vet, User $validatedBy): Vet`, `suspend(Vet $vet): Vet`, `unsuspend(Vet $vet): Vet`. No se reutiliza `update()` para estas operaciones.
**Justificación:** Estas operaciones tienen semántica de negocio específica (validate setea `validated_at` + `validated_by`; suspend solo setea `suspended_at`; unsuspend limpia `suspended_at`). Meterlas en `update()` genérico haría posible que alguien pase `validated_at` directamente en el body de un PUT, saltándose la lógica de auditoría. La separación en métodos específicos es una barrera de seguridad semántica.
**Alternativa descartada:** Reutilizar `update()`. Descartado por riesgo de bypass de lógica de negocio.

### DEC-07 — Cambio de rol de miembro: método específico en UserProfileService
**Decisión:** Se crea `UserProfileService` con métodos `list(Vet $vet): Collection`, `addMember(Vet $vet, User $user, Role $role): UserProfile`, `removeMember(UserProfile $profile): void`, `changeRole(UserProfile $profile, Role $role): UserProfile`. Este service inyecta `UserProfileRepositoryInterface` y `RoleRepositoryInterface`.
**Justificación:** La lógica de miembros (unicidad, validación de roles de tenant, scope de vet) justifica un servicio propio. Meter esta lógica en `VetService` lo hace God Object. El patrón del proyecto es un service por entidad principal.
**Alternativa descartada:** Extender `VetService`. Descartado por tamaño y cohesión.

### DEC-08 — Roles válidos para miembros: constante en UserProfileService, no hardcodeada en FormRequest
**Decisión:** La lista de roles de tenant válidos (`vet`, `vet-assistant`, `vet-administrative`, `client-owner`, `client-manager`, `client-administrative`) se define como constante de clase `TENANT_ROLES` en `UserProfileService`. El `AssignMemberRequest` y `ChangeRoleMemberRequest` validan que el guid del rol recibido exista en la tabla `roles` Y que su `name` esté en ese conjunto. La validación de `name in TENANT_ROLES` se hace con una `Rule::exists` combinada con un `whereIn('name', ...)`.
**Justificación:** La lista de roles de tenant ya existe en `RoleSeeder`. Hardcodearla en el FormRequest la duplica. Al validar con `exists:roles,guid` + scope de `whereIn('name', ...)`, la validación se resuelve en una sola query que verifica existencia y pertenencia al conjunto correcto simultáneamente.
**Alternativa descartada:** Hardcodear en el FormRequest. Descartado por duplicación.

### DEC-09 — Ampliar UserProfileRepositoryInterface: agregar list y update
**Decisión:** Se agregan dos métodos a la interfaz: `listForVet(Vet $vet): Collection` y `update(UserProfile $profile, array $data): UserProfile`. El método `update` ya existe en `BaseRepositoryEloquent` pero no está declarado en la interfaz — se declara explícitamente para que el contrato sea claro.
**Justificación:** El controller de miembros necesita listar perfiles de una vet (con scope de tenant) y actualizar el role_id. No agregar estos métodos obligaría a hacer queries directas al modelo desde el service, violando la regla arquitectónica.
**Alternativa descartada:** Heredar `update` de BaseRepositoryEloquent sin declararlo en la interfaz. Descartado porque la interfaz es el contrato que el service inyecta — debe ser completa y explícita.

### DEC-10 — ContactService: agregar método findByGuid con verificación de ownership
**Decisión:** Se agrega `findByGuidForContactable(string $guid, Model $contactable): ?Contact` al `ContactService`. Busca el contacto por guid y verifica que su `contactable_type` y `contactable_id` coincidan con el contactable recibido. El controller usa este método en lugar de buscar el contacto directamente y luego verificar.
**Justificación:** Si el controller busca por guid sin verificar el ownership, un usuario podría modificar o eliminar un contacto de otro tenant pasando su guid. La verificación en el service es la capa correcta para esto — el controller no debería tener lógica de negocio de autorización más allá del middleware.
**Alternativa descartada:** Verificar ownership en el controller. Descartado porque es lógica de negocio, no de presentación.

### DEC-11 — CountryController: sin FormRequest (GET sin body)
**Decisión:** `CountryController` no usa FormRequests porque ambos endpoints son GET sin parámetros de body. El parámetro `{guid}` para `/countries/{guid}/document-types` se recibe como string en el método del controller.
**Justificación:** Los FormRequests son para validar body de requests. Una ruta GET que solo resuelve un guid en la URL no necesita FormRequest — el controller resuelve la entidad y devuelve 404 si no existe.
**Alternativa descartada:** FormRequest vacío por consistencia. Descartado por innecesario y ruidoso.

### DEC-12 — Acceso a endpoints de countries: auth:sanctum requerido (no público)
**Decisión:** `GET /v1/countries` y `GET /v1/countries/{guid}/document-types` requieren `auth:sanctum`. No son públicos.
**Justificación:** El brief dice "público o autenticado" — se elige autenticado. Estos datos se usan en formularios de registro/edición de vets que solo aparecen en la UI autenticada. Hacerlos públicos expone información de configuración del sistema sin beneficio real. Si en el futuro se necesita acceso público (ej: formulario de registro de nueva vet), se mueve a un grupo sin middleware.
**Alternativa descartada:** Sin middleware. Descartado por exposición innecesaria.

### DEC-13 — VetController para admin y VetTenantController para tenant: dos controllers separados
**Decisión:** `AdminVetController` para las rutas bajo `/v1/admin/vets` y `VetController` para las rutas bajo `/v1/vets/{vet}`.
**Justificación:** Las audiencias son distintas (superadmin vs. miembro del tenant), los middlewares son distintos, los recursos (Resources) pueden diferir en campos expuestos, y la lógica de autorización es diferente. Un solo controller con condicionales de `if ($user->hasRole('super-admin'))` sería confuso y propenso a errores de autorización.
**Alternativa descartada:** Un solo VetController con branch interno. Descartado por complejidad y riesgo de autorización.

---

## Cambios en BACKEND

### Archivos a crear

---

#### `back/app/Http/Controllers/V1/CountryController.php`
**Propósito:** Endpoints de solo lectura para países y tipos de documento. Sin lógica de escritura.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CountryResource;
use App\Http\Resources\V1\DocumentTypeResource;
use App\Services\CountryService;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    public function __construct(
        private CountryService $countryService,
    ) {}

    public function index(): JsonResponse
    {
        // Llama countryService->list() → Collection de Country
        // Devuelve CountryResource::collection($countries)
    }

    public function documentTypes(string $guid): JsonResponse
    {
        // Resuelve Country por guid → 404 si no existe
        // Llama countryService->documentTypes($country) → Collection de DocumentType
        // Devuelve DocumentTypeResource::collection($types)
    }
}
```

**Dependencias inyectadas:** `CountryService`.

---

#### `back/app/Services/CountryService.php`
**Propósito:** Servicio de lectura para países y tipos de documento.

```php
namespace App\Services;

use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

class CountryService
{
    public function __construct(
        private CountryRepositoryInterface     $countryRepository,
        private DocumentTypeRepositoryInterface $documentTypeRepository,
    ) {}

    public function list(): Collection
    {
        return $this->countryRepository->all();
    }

    public function findByGuid(string $guid): ?Country
    {
        return $this->countryRepository->findByGuid($guid);
    }

    public function documentTypes(Country $country): Collection
    {
        return $this->documentTypeRepository->findByCountry($country->id);
    }
}
```

**Dependencias inyectadas:** `CountryRepositoryInterface`, `DocumentTypeRepositoryInterface`.

---

#### `back/app/Http/Resources/V1/CountryResource.php`
**Propósito:** Representación JSON de un país.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'         => $this->guid,
            'name'         => $this->name,
            'iso_code'     => $this->iso_code,
            'phone_prefix' => $this->phone_prefix,
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/DocumentTypeResource.php`
**Propósito:** Representación JSON de un tipo de documento. No expone `validation_regex` al frontend (dato interno del backend).

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'    => $this->guid,
            'name'    => $this->name,
            'country' => new CountryResource($this->whenLoaded('country')),
        ];
    }
}
```

**Nota:** `validation_regex` no se expone. El frontend no necesita el regex — la validación ocurre en el backend al hacer POST/PUT de una vet.

---

#### `back/app/Http/Controllers/V1/AdminVetController.php`
**Propósito:** CRUD de veterinarias para el panel superadmin. Incluye acciones de validación y suspensión.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vets\IndexVetRequest;
use App\Http\Requests\Vets\StoreVetRequest;
use App\Http\Requests\Vets\UpdateVetRequest;
use App\Http\Resources\V1\VetResource;
use App\Services\VetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVetController extends Controller
{
    public function __construct(
        private VetService $vetService,
    ) {}

    public function index(IndexVetRequest $request): JsonResponse
    {
        // $filters = $request->validated()
        // $perPage = $request->integer('per_page', 15)
        // $paginator = $this->vetService->paginate($filters, $perPage)
        // return $this->makeSuccessPagination($paginator, VetResource::class)
    }

    public function store(StoreVetRequest $request): JsonResponse
    {
        // $vet = $this->vetService->create($request->validated())
        // return $this->makeSuccess(new VetResource($vet), 'Veterinaria creada correctamente.', 201)
    }

    public function show(string $guid): JsonResponse
    {
        // $vet = $this->vetService->findByGuid($guid) → 404 si null
        // $vet->load(['country', 'documentType', 'validatedBy'])
        // return $this->makeSuccess(new VetResource($vet))
    }

    public function update(UpdateVetRequest $request, string $guid): JsonResponse
    {
        // $vet = $this->vetService->findByGuid($guid) → 404 si null
        // $vet = $this->vetService->update($vet, $request->validated())
        // return $this->makeSuccess(new VetResource($vet), 'Veterinaria actualizada correctamente.')
    }

    public function validate(Request $request, string $guid): JsonResponse
    {
        // $vet = $this->vetService->findByGuid($guid) → 404 si null
        // if ($vet->validated_at) → return $this->makeError(null, 'La veterinaria ya está validada.', 422)
        // $vet = $this->vetService->validate($vet, $request->user())
        // return $this->makeSuccess(new VetResource($vet), 'Veterinaria validada correctamente.')
    }

    public function suspend(string $guid): JsonResponse
    {
        // $vet = $this->vetService->findByGuid($guid) → 404 si null
        // if ($vet->suspended_at) → return $this->makeError(null, 'La veterinaria ya está suspendida.', 422)
        // $vet = $this->vetService->suspend($vet)
        // return $this->makeSuccess(new VetResource($vet), 'Veterinaria suspendida correctamente.')
    }

    public function unsuspend(string $guid): JsonResponse
    {
        // $vet = $this->vetService->findByGuid($guid) → 404 si null
        // if (!$vet->suspended_at) → return $this->makeError(null, 'La veterinaria no está suspendida.', 422)
        // $vet = $this->vetService->unsuspend($vet)
        // return $this->makeSuccess(new VetResource($vet), 'Veterinaria reactivada correctamente.')
    }
}
```

**Dependencias inyectadas:** `VetService`.

---

#### `back/app/Http/Controllers/V1/VetController.php`
**Propósito:** Lectura y actualización de la propia veterinaria para el panel tenant. Solo accede a la vet resuelta por el middleware.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vets\UpdateVetRequest;
use App\Http\Resources\V1\VetResource;
use App\Services\VetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetController extends Controller
{
    public function __construct(
        private VetService $vetService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        // $vet = $request->attributes->get('current_vet')
        // $vet->load(['country', 'documentType', 'contacts'])
        // return $this->makeSuccess(new VetResource($vet))
    }

    public function update(UpdateVetRequest $request): JsonResponse
    {
        // $vet = $request->attributes->get('current_vet')
        // $vet = $this->vetService->update($vet, $request->validated())
        // return $this->makeSuccess(new VetResource($vet), 'Datos actualizados correctamente.')
    }
}
```

**Nota de implementación:** El controller NO recibe `{vet}` como argumento — el middleware ya resolvió la vet y la puso en `$request->attributes`. Tomar el guid de la URL y hacer otra búsqueda sería ineficiente y redundante.
**Dependencias inyectadas:** `VetService`.

---

#### `back/app/Http/Controllers/V1/MemberController.php`
**Propósito:** Gestión de miembros (UserProfiles) de un tenant.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\AssignMemberRequest;
use App\Http\Requests\Members\ChangeRoleMemberRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __construct(
        private UserProfileService $userProfileService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // $vet = $request->attributes->get('current_vet')
        // $members = $this->userProfileService->list($vet)
        // return $this->makeSuccess(UserProfileResource::collection($members))
    }

    public function store(AssignMemberRequest $request): JsonResponse
    {
        // $vet = $request->attributes->get('current_vet')
        // $data = $request->validated()
        // Resolver User por guid: $user = $this->userProfileService->resolveUser($data['user_guid'])
        //   → si null: 404
        // Resolver Role por guid: $role = $this->userProfileService->resolveRole($data['role_guid'])
        //   → si null: 404 (aunque exists validation lo cubre)
        // $profile = $this->userProfileService->addMember($vet, $user, $role)
        //   → si ya existe: makeError(null, 'El usuario ya es miembro de esta veterinaria.', 422)
        // return $this->makeSuccess(new UserProfileResource($profile), 'Miembro agregado correctamente.', 201)
    }

    public function destroy(Request $request, string $guid): JsonResponse
    {
        // $vet = $request->attributes->get('current_vet')
        // $currentProfile = $request->attributes->get('current_profile')
        // $profile = $this->userProfileService->findByGuidForVet($guid, $vet) → 404 si null
        // Protección: no puede eliminarse a sí mismo
        // if ($profile->id === $currentProfile->id) → makeError(null, 'No podés eliminarte a vos mismo del tenant.', 403)
        // $this->userProfileService->removeMember($profile)
        // return $this->makeSuccess(null, 'Miembro eliminado correctamente.')
    }

    public function changeRole(ChangeRoleMemberRequest $request, string $guid): JsonResponse
    {
        // $vet = $request->attributes->get('current_vet')
        // $profile = $this->userProfileService->findByGuidForVet($guid, $vet) → 404 si null
        // Resolver Role por guid desde $request->validated()['role_guid']
        // $profile = $this->userProfileService->changeRole($profile, $role)
        // return $this->makeSuccess(new UserProfileResource($profile), 'Rol actualizado correctamente.')
    }
}
```

**Dependencias inyectadas:** `UserProfileService`.

---

#### `back/app/Http/Controllers/V1/ContactController.php`
**Propósito:** CRUD de contactos polimórficos para vet o para UserProfile. Resuelve el contactable según los parámetros de ruta disponibles.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Resources\V1\ContactResource;
use App\Models\UserProfile;
use App\Services\ContactService;
use App\Services\UserProfileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        private ContactService      $contactService,
        private UserProfileService  $userProfileService,
    ) {}

    public function index(Request $request, string $vet, ?string $profile = null): JsonResponse
    {
        // $contactable = $this->resolveContactable($request, $profile)
        // → si null: ya devuelve JsonResponse de error (el método retorna mixed en pseudo)
        // $contacts = $contactable->contacts()->get()
        // return $this->makeSuccess(ContactResource::collection($contacts))
    }

    public function store(StoreContactRequest $request, string $vet, ?string $profile = null): JsonResponse
    {
        // $contactable = $this->resolveContactable($request, $profile) → error si null
        // $contact = $this->contactService->create($contactable, $request->validated())
        // return $this->makeSuccess(new ContactResource($contact), 'Contacto creado correctamente.', 201)
    }

    public function update(UpdateContactRequest $request, string $vet, ?string $profile = null, string $guid): JsonResponse
    {
        // $contactable = $this->resolveContactable($request, $profile) → error si null
        // $contact = $this->contactService->findByGuidForContactable($guid, $contactable) → 404 si null
        // $contact = $this->contactService->update($contact, $request->validated())
        // return $this->makeSuccess(new ContactResource($contact), 'Contacto actualizado correctamente.')
    }

    public function destroy(Request $request, string $vet, ?string $profile = null, string $guid): JsonResponse
    {
        // $contactable = $this->resolveContactable($request, $profile) → error si null
        // $contact = $this->contactService->findByGuidForContactable($guid, $contactable) → 404 si null
        // $this->contactService->destroy($contact)
        // return $this->makeSuccess(null, 'Contacto eliminado correctamente.')
    }

    /**
     * Resuelve el contactable (Vet o UserProfile) verificando pertenencia al tenant.
     * Retorna null y escribe la respuesta de error si el contexto no es válido.
     * En implementación real, lanzar excepción que el handler mapea, o retornar Model directamente.
     */
    private function resolveContactable(Request $request, ?string $profileGuid): Model
    {
        $vet = $request->attributes->get('current_vet');

        if ($profileGuid === null) {
            // Contactable es la Vet del middleware
            return $vet;
        }

        // Contactable es un UserProfile: verificar que pertenece a la vet actual
        $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);

        if (!$profile) {
            // Lanzar ModelNotFoundException o AbortException → manejada por el handler global
            abort(404, 'Perfil no encontrado en esta veterinaria.');
        }

        return $profile;
    }
}
```

**Nota de implementación:** El `abort(404)` en `resolveContactable` es manejado por el handler global de excepciones en `bootstrap/app.php` (`withExceptions`) que ya devuelve `ResponseHelper::makeFromException($e)` para requests JSON. Esto es consistente con el patrón del proyecto.
**Dependencias inyectadas:** `ContactService`, `UserProfileService`.

---

#### `back/app/Services/UserProfileService.php`
**Propósito:** Lógica de negocio para gestión de miembros de un tenant.

```php
namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Database\Eloquent\Collection;

class UserProfileService
{
    /** Roles válidos para ser asignados dentro de un tenant. */
    public const TENANT_ROLES = [
        'vet',
        'vet-assistant',
        'vet-administrative',
        'client-owner',
        'client-manager',
        'client-administrative',
    ];

    public function __construct(
        private UserProfileRepositoryInterface $userProfileRepository,
        private UserRepositoryInterface        $userRepository,
        private RoleRepositoryInterface        $roleRepository,
    ) {}

    public function list(Vet $vet): Collection
    {
        // Llama $this->userProfileRepository->listForVet($vet) con eager load de user y role
        return $this->userProfileRepository->listForVet($vet);
    }

    public function findByGuid(string $guid): ?UserProfile
    {
        return $this->userProfileRepository->findByGuid($guid);
    }

    /**
     * Busca un UserProfile por guid verificando que pertenece al tenant dado.
     * Retorna null si no existe o si pertenece a otro tenant.
     */
    public function findByGuidForVet(string $guid, Vet $vet): ?UserProfile
    {
        $profile = $this->userProfileRepository->findByGuid($guid);

        if (!$profile) {
            return null;
        }

        if ($profile->authenticatable_type !== 'vet' || $profile->authenticatable_id !== $vet->id) {
            return null;
        }

        return $profile;
    }

    public function resolveUser(string $guid): ?User
    {
        return $this->userRepository->findByGuid($guid);
    }

    public function resolveRole(string $guid): ?Role
    {
        return $this->roleRepository->findByGuid($guid);
    }

    /**
     * Agrega un usuario como miembro de un tenant con un rol específico.
     * Precondición: validar en el controller que el usuario no es ya miembro (findForUserAndVet === null).
     */
    public function addMember(Vet $vet, User $user, Role $role): UserProfile
    {
        // Verifica si ya existe → lanza excepción de duplicado
        $existing = $this->userProfileRepository->findForUserAndVet($user, $vet);

        if ($existing) {
            throw new \RuntimeException('El usuario ya es miembro de esta veterinaria.');
        }

        return $this->userProfileRepository->create([
            'user_id'             => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'  => $vet->id,
            'role_id'             => $role->id,
        ]);
    }

    public function removeMember(UserProfile $profile): void
    {
        $this->userProfileRepository->destroy($profile);
    }

    public function changeRole(UserProfile $profile, Role $role): UserProfile
    {
        return $this->userProfileRepository->update($profile, ['role_id' => $role->id]);
    }
}
```

**Dependencias inyectadas:** `UserProfileRepositoryInterface`, `UserRepositoryInterface`, `RoleRepositoryInterface`.

---

#### `back/app/Http/Resources/V1/VetResource.php`
**Propósito:** Representación JSON de una veterinaria. Adapta campos según contexto (admin vs. tenant) usando `whenLoaded`.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'                => $this->guid,
            'name'                => $this->name,
            'slug'                => $this->slug,
            'tax_id'              => $this->tax_id,
            'registration_number' => $this->registration_number,
            'logo_path'           => $this->logo_path,
            'pdf_title'           => $this->pdf_title,
            'pdf_subtitle'        => $this->pdf_subtitle,
            'validated_at'        => $this->validated_at?->toISOString(),
            'suspended_at'        => $this->suspended_at?->toISOString(),
            'is_active'           => $this->validated_at !== null && $this->suspended_at === null,
            'country'             => new CountryResource($this->whenLoaded('country')),
            'document_type'       => new DocumentTypeResource($this->whenLoaded('documentType')),
            'validated_by'        => $this->whenLoaded('validatedBy', fn() => [
                'guid' => $this->validatedBy->guid,
                'name' => $this->validatedBy->name,
            ]),
            'contacts'            => ContactResource::collection($this->whenLoaded('contacts')),
            'created_at'          => $this->created_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/UserProfileResource.php`
**Propósito:** Representación JSON de un UserProfile (miembro de tenant) con su usuario y rol eager loaded.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'       => $this->guid,
            'user'       => $this->whenLoaded('user', fn() => [
                'guid'       => $this->user->guid,
                'name'       => $this->user->name,
                'first_name' => $this->user->first_name,
                'last_name'  => $this->user->last_name,
                'email'      => $this->user->email,
            ]),
            'role'       => $this->whenLoaded('role', fn() => [
                'guid' => $this->role->guid,
                'name' => $this->role->name,
            ]),
            'contacts'   => ContactResource::collection($this->whenLoaded('contacts')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/ContactResource.php`
**Propósito:** Representación JSON de un contacto polimórfico.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'           => $this->guid,
            'type'           => $this->type->value,  // 'email' | 'phone' | 'whatsapp'
            'label'          => $this->label,
            'value'          => $this->value,
            'is_primary'     => $this->is_primary,
            'use_for_alerts' => $this->use_for_alerts,
            'created_at'     => $this->created_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Requests/Vets/IndexVetRequest.php`
**Propósito:** Valida filtros del listado de vets para admin.

```php
namespace App\Http\Requests\Vets;

use Illuminate\Foundation\Http\FormRequest;

class IndexVetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'    => ['nullable', 'string', 'max:100'],
            'validated' => ['nullable', 'boolean'],
            'suspended' => ['nullable', 'boolean'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

---

#### `back/app/Http/Requests/Vets/StoreVetRequest.php`
**Propósito:** Valida datos de creación de una veterinaria, incluyendo tax_id contra el regex del DocumentType.

```php
namespace App\Http\Requests\Vets;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:150'],
            'country_guid'        => ['required', 'string', 'exists:countries,guid'],
            'document_type_guid'  => ['required', 'string', 'exists:document_types,guid'],
            'tax_id'              => ['required', 'string', 'max:50', $this->taxIdRule()],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'logo_path'           => ['nullable', 'string', 'max:500'],
            'pdf_title'           => ['nullable', 'string', 'max:200'],
            'pdf_subtitle'        => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * Regla que valida tax_id contra el validation_regex del DocumentType seleccionado.
     * Se ejecuta solo si document_type_guid existe (las reglas se evalúan en orden).
     */
    private function taxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');
            $docType = DocumentType::where('guid', $docTypeGuid)->first();

            if (!$docType || !$docType->validation_regex) {
                return; // Si no hay regex, no validamos formato
            }

            $pattern = '/' . $docType->validation_regex . '/';

            if (!preg_match($pattern, $value)) {
                $fail("El formato del {$docType->name} es inválido.");
            }
        };
    }

    public function messages(): array
    {
        return [
            'name.required'               => 'El nombre es obligatorio.',
            'name.max'                    => 'El nombre no puede superar 150 caracteres.',
            'country_guid.required'       => 'El país es obligatorio.',
            'country_guid.exists'         => 'El país seleccionado no existe.',
            'document_type_guid.required' => 'El tipo de documento es obligatorio.',
            'document_type_guid.exists'   => 'El tipo de documento seleccionado no existe.',
            'tax_id.required'             => 'El número de documento fiscal es obligatorio.',
            'tax_id.max'                  => 'El número de documento no puede superar 50 caracteres.',
        ];
    }
}
```

**Nota crítica de implementación:** El dev debe verificar que los `validation_regex` almacenados en el seeder no contengan delimitadores de PHP (el `CountrySeeder` ya los almacena sin delimitadores, ej: `^\d{2}-\d{8}-\d{1}$`). El `taxIdRule()` los envuelve con `/` al hacer `preg_match`. Si algún regex almacenado contiene `/` dentro, deberá escaparse o usarse un delimitador diferente (ej: `~`). Revisar al ejecutar.

---

#### `back/app/Http/Requests/Vets/UpdateVetRequest.php`
**Propósito:** Valida datos de actualización de una veterinaria. Mismas reglas que Store pero todos los campos son opcionales. Reutiliza `taxIdRule`.

```php
namespace App\Http\Requests\Vets;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                => ['sometimes', 'string', 'max:150'],
            'document_type_guid'  => ['sometimes', 'string', 'exists:document_types,guid'],
            'tax_id'              => ['sometimes', 'string', 'max:50', $this->taxIdRule()],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'logo_path'           => ['nullable', 'string', 'max:500'],
            'pdf_title'           => ['nullable', 'string', 'max:200'],
            'pdf_subtitle'        => ['nullable', 'string', 'max:200'],
        ];
    }

    private function taxIdRule(): \Closure
    {
        // Misma lógica que StoreVetRequest::taxIdRule()
        // Considera: si se envía tax_id sin document_type_guid, usar el document_type_id actual de la vet
        // En ese caso, la vet actual está disponible via route model binding o el service.
        // Decisión: si se envía tax_id, DEBE enviarse document_type_guid también.
        // De lo contrario, no aplicar validación de regex (campo not present).
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');

            if (!$docTypeGuid) {
                return; // No hay cambio de tipo de doc, no re-validamos
            }

            $docType = DocumentType::where('guid', $docTypeGuid)->first();

            if (!$docType || !$docType->validation_regex) {
                return;
            }

            $pattern = '/' . $docType->validation_regex . '/';

            if (!preg_match($pattern, $value)) {
                $fail("El formato del {$docType->name} es inválido.");
            }
        };
    }

    public function messages(): array
    {
        return [
            'name.max'                   => 'El nombre no puede superar 150 caracteres.',
            'document_type_guid.exists'  => 'El tipo de documento seleccionado no existe.',
            'tax_id.max'                 => 'El número de documento no puede superar 50 caracteres.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/AssignMemberRequest.php`
**Propósito:** Valida los datos para agregar un miembro a un tenant.

```php
namespace App\Http\Requests\Members;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignMemberRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'user_guid' => ['required', 'string', 'exists:users,guid'],
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::TENANT_ROLES);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_guid.required' => 'El usuario es obligatorio.',
            'user_guid.exists'   => 'El usuario seleccionado no existe.',
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para un miembro de veterinaria.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/ChangeRoleMemberRequest.php`
**Propósito:** Valida el nuevo rol al cambiar el rol de un miembro.

```php
namespace App\Http\Requests\Members;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeRoleMemberRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::TENANT_ROLES);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para un miembro de veterinaria.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Contacts/StoreContactRequest.php`
**Propósito:** Valida datos de creación de un contacto. Incluye validación E.164 para phone y whatsapp.

```php
namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'label'          => ['nullable', 'string', 'max:100'],
            'value'          => ['required', 'string', 'max:200', $this->valueRule()],
            'is_primary'     => ['nullable', 'boolean'],
            'use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Si type es 'phone' o 'whatsapp', aplica validación E.164.
     * Si type es 'email', aplica validación de email.
     */
    private function valueRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $type = $this->input('type');

            if (in_array($type, ['phone', 'whatsapp'])) {
                if (!preg_match('/^\+?[1-9]\d{7,14}$/', $value)) {
                    $fail('El número de teléfono debe estar en formato E.164 (ej: +5491112345678).');
                }
            }

            if ($type === 'email') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('El email no tiene un formato válido.');
                }
            }
        };
    }

    public function messages(): array
    {
        return [
            'type.required'  => 'El tipo de contacto es obligatorio.',
            'type.in'        => 'El tipo de contacto debe ser email, phone o whatsapp.',
            'value.required' => 'El valor del contacto es obligatorio.',
            'value.max'      => 'El valor no puede superar 200 caracteres.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Contacts/UpdateContactRequest.php`
**Propósito:** Valida datos de actualización de un contacto. Similar a Store pero con `sometimes`.

```php
namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'           => ['sometimes', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'label'          => ['nullable', 'string', 'max:100'],
            'value'          => ['sometimes', 'string', 'max:200', $this->valueRule()],
            'is_primary'     => ['nullable', 'boolean'],
            'use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    private function valueRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            // El type puede venir en el body (si se está actualizando) o no.
            // Si no viene type en el body, el contacto ya tiene un type en DB.
            // En update, el dev debe precargar el tipo actual si no se envía.
            // Decisión: si se envía value sin type, asumir que el type no cambia.
            // La validación E.164 aplica solo si se envía type=phone|whatsapp.
            $type = $this->input('type');

            if (!$type) {
                return; // Sin type en el body, no podemos validar el formato
            }

            if (in_array($type, ['phone', 'whatsapp'])) {
                if (!preg_match('/^\+?[1-9]\d{7,14}$/', $value)) {
                    $fail('El número de teléfono debe estar en formato E.164 (ej: +5491151234567).');
                }
            }

            if ($type === 'email') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('El email no tiene un formato válido.');
                }
            }
        };
    }

    public function messages(): array
    {
        return [
            'type.in'    => 'El tipo de contacto debe ser email, phone o whatsapp.',
            'value.max'  => 'El valor no puede superar 200 caracteres.',
        ];
    }
}
```

---

#### `back/routes/api/countries.php`
**Propósito:** Rutas de países y tipos de documento.

```php
<?php

use App\Http\Controllers\V1\CountryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/countries')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [CountryController::class, 'index']);
    Route::get('/{guid}/document-types', [CountryController::class, 'documentTypes']);
});
```

---

#### `back/routes/api/vets.php`
**Propósito:** Rutas de vets (admin y tenant).

```php
<?php

use App\Http\Controllers\V1\AdminVetController;
use App\Http\Controllers\V1\ContactController;
use App\Http\Controllers\V1\MemberController;
use App\Http\Controllers\V1\VetController;
use Illuminate\Support\Facades\Route;

// --- Panel SuperAdmin ---
Route::prefix('v1/admin/vets')->middleware(['auth:sanctum', 'can:vets.read'])->group(function () {
    Route::get('/', [AdminVetController::class, 'index'])->withoutMiddleware('can:vets.read');
    // NOTA: index necesita 'vets.read'; store necesita 'vets.create', etc.
    // Ver sección de Rutas API más abajo para el detalle de permisos por ruta.
});

// --- Panel Tenant ---
Route::prefix('v1/vets/{vet}')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetController::class, 'show']);
    Route::put('/', [VetController::class, 'update']);

    // Miembros
    Route::prefix('members')->group(function () {
        Route::get('/', [MemberController::class, 'index']);
        Route::post('/', [MemberController::class, 'store']);
        Route::delete('/{guid}', [MemberController::class, 'destroy']);
        Route::patch('/{guid}/role', [MemberController::class, 'changeRole']);

        // Contactos de un miembro
        Route::prefix('/{profile}/contacts')->group(function () {
            Route::get('/', [ContactController::class, 'index']);
            Route::post('/', [ContactController::class, 'store']);
            Route::put('/{guid}', [ContactController::class, 'update']);
            Route::delete('/{guid}', [ContactController::class, 'destroy']);
        });
    });

    // Contactos de la vet
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::put('/{guid}', [ContactController::class, 'update']);
        Route::delete('/{guid}', [ContactController::class, 'destroy']);
    });
});
```

**Nota de implementación:** Ver la sección "Rutas API" para el detalle completo con permisos por ruta.

---

### Archivos a modificar

---

#### `back/app/Contracts/Repositories/VetRepositoryInterface.php`
**Cambio:** Agregar método `paginate` para soportar el listado paginado con filtros del panel admin.

**Antes:**
```php
interface VetRepositoryInterface
{
    public function findByGuid(string $guid): ?Vet;
    public function findBySlug(string $slug): ?Vet;
    public function create(array $data): Vet;
    public function update(Vet $vet, array $data): Vet;
}
```

**Después (agregar):**
```php
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VetRepositoryInterface
{
    public function findByGuid(string $guid): ?Vet;
    public function findBySlug(string $slug): ?Vet;
    public function create(array $data): Vet;
    public function update(Vet $vet, array $data): Vet;
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
```

---

#### `back/app/Repositories/VetRepositoryEloquent.php`
**Cambio:** Implementar el método `paginate` con soporte para filtros `search`, `validated`, `suspended`.

**Agregar a la clase:**
```php
public function paginate(array $filters, int $perPage): LengthAwarePaginator
{
    $query = $this->newQuery()->with(['country', 'documentType']);

    if (!empty($filters['search'])) {
        $query->where(function ($q) use ($filters) {
            $q->where('name', 'like', '%' . $filters['search'] . '%')
              ->orWhere('tax_id', 'like', '%' . $filters['search'] . '%')
              ->orWhere('slug', 'like', '%' . $filters['search'] . '%');
        });
    }

    if (isset($filters['validated'])) {
        $query->when(
            filter_var($filters['validated'], FILTER_VALIDATE_BOOLEAN),
            fn($q) => $q->whereNotNull('validated_at'),
            fn($q) => $q->whereNull('validated_at'),
        );
    }

    if (isset($filters['suspended'])) {
        $query->when(
            filter_var($filters['suspended'], FILTER_VALIDATE_BOOLEAN),
            fn($q) => $q->whereNotNull('suspended_at'),
            fn($q) => $q->whereNull('suspended_at'),
        );
    }

    return $query->latest()->paginate($perPage);
}
```

---

#### `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php`
**Cambio:** Agregar `listForVet`, `update` y `destroy` a la interfaz.

**Antes:**
```php
interface UserProfileRepositoryInterface
{
    public function findByGuid(string $guid): ?UserProfile;
    public function findForUserAndVet(User $user, Vet $vet): ?UserProfile;
    public function create(array $data): UserProfile;
}
```

**Después:**
```php
use Illuminate\Database\Eloquent\Collection;

interface UserProfileRepositoryInterface
{
    public function findByGuid(string $guid): ?UserProfile;
    public function findForUserAndVet(User $user, Vet $vet): ?UserProfile;
    public function create(array $data): UserProfile;
    public function listForVet(Vet $vet): Collection;
    public function update(UserProfile $profile, array $data): UserProfile;
    public function destroy(UserProfile $profile): bool|null;
}
```

---

#### `back/app/Repositories/UserProfileRepositoryEloquent.php`
**Cambio:** Implementar `listForVet`. Los métodos `update` y `destroy` ya existen en `BaseRepositoryEloquent` con las firmas correctas, solo necesitan estar explícitamente declarados en la interfaz (el repositorio ya los hereda).

**Agregar a la clase:**
```php
public function listForVet(Vet $vet): Collection
{
    return $this->newQuery()
        ->with(['user', 'role'])
        ->where('authenticatable_type', 'vet')
        ->where('authenticatable_id', $vet->id)
        ->get();
}
```

**Nota:** `update` y `destroy` son heredados de `BaseRepositoryEloquent`. No es necesario redefinirlos. La interfaz los declara explícitamente para el contrato; la implementación los satisface por herencia. Si el tipo de retorno de `destroy` en la interfaz difiere del de Base, puede ser necesario un cast explícito — verificar en implementación.

---

#### `back/app/Services/VetService.php`
**Cambio:** Agregar métodos `paginate`, `validate`, `suspend`, `unsuspend`.

**Agregar imports:**
```php
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
```

**Agregar métodos:**
```php
public function paginate(array $filters, int $perPage): LengthAwarePaginator
{
    return $this->vetRepository->paginate($filters, $perPage);
}

public function validate(Vet $vet, User $validatedBy): Vet
{
    return $this->vetRepository->update($vet, [
        'validated_at' => Carbon::now(),
        'validated_by' => $validatedBy->id,
    ]);
}

public function suspend(Vet $vet): Vet
{
    return $this->vetRepository->update($vet, [
        'suspended_at' => Carbon::now(),
    ]);
}

public function unsuspend(Vet $vet): Vet
{
    return $this->vetRepository->update($vet, [
        'suspended_at' => null,
    ]);
}
```

---

#### `back/app/Services/ContactService.php`
**Cambio:** Agregar método `findByGuidForContactable` para verificar ownership.

**Agregar import:**
```php
use Illuminate\Database\Eloquent\Model;
```

**Agregar método:**
```php
/**
 * Busca un contacto por guid y verifica que pertenece al contactable dado.
 * Retorna null si no existe o si el contactable no coincide.
 */
public function findByGuidForContactable(string $guid, Model $contactable): ?Contact
{
    $contact = $this->contactRepository->findByGuid($guid);

    if (!$contact) {
        return null;
    }

    // Verificar ownership: el contactable_type almacenado usa el morphMap alias
    // ('vet' o 'user_profile'), no el nombre de clase completo.
    $contactableType = array_search(get_class($contactable), \Illuminate\Database\Eloquent\Relations\Relation::morphMap())
        ?: get_class($contactable);

    if ($contact->contactable_type !== $contactableType || $contact->contactable_id !== $contactable->id) {
        return null;
    }

    return $contact;
}
```

**Nota crítica de implementación:** El campo `contacts.contactable_type` almacena el alias del morphMap (`'vet'` o `'user_profile'`), no el nombre de clase completo. Al verificar ownership, se debe comparar con el alias, no con `get_class($contactable)`. El pseudocódigo usa `array_search` sobre `Relation::morphMap()` para obtener el alias desde la clase. Si la clase no está en el morphMap, fallback al nombre completo (no debería ocurrir en este sistema).

---

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar binding de `CountryService` y `UserProfileService`.

**Nota:** Los services en Laravel se resuelven automáticamente por el container sin necesidad de binding explícito, dado que son clases concretas cuyas dependencias son interfaces ya registradas. **No es necesario agregar bindings en AppServiceProvider** para `CountryService`, `UserProfileService`, `VetService` o `ContactService` — el container los resuelve automáticamente. Este archivo NO necesita modificación para esta iteración.

---

#### `back/database/seeders/PermissionSeeder.php`
**Cambio:** Agregar los 5 permisos nuevos de gestión de vets.

**Agregar al array `$permissions`:**
```php
'vets.read',
'vets.create',
'vets.update',
'vets.delete',
'vets.validate',
```

---

#### `back/database/seeders/RoleSeeder.php`
**Cambio:** Agregar los nuevos permisos al rol `super-admin`. El `admin` no recibe permisos de vets por defecto (decisión de negocio: gestión de tenants es solo superadmin).

**Modificar:** Después de crear los roles y permisos, el `$superAdmin->syncPermissions(Permission::all())` ya incluye todos los permisos nuevos automáticamente, ya que sincroniza con TODOS los permisos existentes. No es necesario cambio adicional en el seeder para que super-admin tenga los nuevos permisos — `syncPermissions(Permission::all())` los toma al re-correr el seeder.

**Verificar:** Que la línea `$superAdmin->syncPermissions(Permission::all())` esté DESPUÉS de que `PermissionSeeder` haya corrido (el `DatabaseSeeder` llama a `PermissionSeeder` antes que `RoleSeeder`, orden correcto ya verificado).

---

### Migraciones
No se crean migraciones nuevas en esta iteración. Todas las tablas necesarias existen.

---

### Rutas API

**Archivo:** `back/routes/api/countries.php` (nuevo)

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| GET | `/v1/countries` | `CountryController@index` | `auth:sanctum` |
| GET | `/v1/countries/{guid}/document-types` | `CountryController@documentTypes` | `auth:sanctum` |

**Archivo:** `back/routes/api/vets.php` (nuevo)

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| GET | `/v1/admin/vets` | `AdminVetController@index` | `auth:sanctum`, `can:vets.read` |
| POST | `/v1/admin/vets` | `AdminVetController@store` | `auth:sanctum`, `can:vets.create` |
| GET | `/v1/admin/vets/{guid}` | `AdminVetController@show` | `auth:sanctum`, `can:vets.read` |
| PUT | `/v1/admin/vets/{guid}` | `AdminVetController@update` | `auth:sanctum`, `can:vets.update` |
| PATCH | `/v1/admin/vets/{guid}/validate` | `AdminVetController@validate` | `auth:sanctum`, `can:vets.validate` |
| PATCH | `/v1/admin/vets/{guid}/suspend` | `AdminVetController@suspend` | `auth:sanctum`, `can:vets.validate` |
| PATCH | `/v1/admin/vets/{guid}/unsuspend` | `AdminVetController@unsuspend` | `auth:sanctum`, `can:vets.validate` |
| GET | `/v1/vets/{vet}` | `VetController@show` | `auth:sanctum`, `vet.tenant` |
| PUT | `/v1/vets/{vet}` | `VetController@update` | `auth:sanctum`, `vet.tenant` |
| GET | `/v1/vets/{vet}/members` | `MemberController@index` | `auth:sanctum`, `vet.tenant` |
| POST | `/v1/vets/{vet}/members` | `MemberController@store` | `auth:sanctum`, `vet.tenant` |
| DELETE | `/v1/vets/{vet}/members/{guid}` | `MemberController@destroy` | `auth:sanctum`, `vet.tenant` |
| PATCH | `/v1/vets/{vet}/members/{guid}/role` | `MemberController@changeRole` | `auth:sanctum`, `vet.tenant` |
| GET | `/v1/vets/{vet}/contacts` | `ContactController@index` | `auth:sanctum`, `vet.tenant` |
| POST | `/v1/vets/{vet}/contacts` | `ContactController@store` | `auth:sanctum`, `vet.tenant` |
| PUT | `/v1/vets/{vet}/contacts/{guid}` | `ContactController@update` | `auth:sanctum`, `vet.tenant` |
| DELETE | `/v1/vets/{vet}/contacts/{guid}` | `ContactController@destroy` | `auth:sanctum`, `vet.tenant` |
| GET | `/v1/vets/{vet}/members/{profile}/contacts` | `ContactController@index` | `auth:sanctum`, `vet.tenant` |
| POST | `/v1/vets/{vet}/members/{profile}/contacts` | `ContactController@store` | `auth:sanctum`, `vet.tenant` |
| PUT | `/v1/vets/{vet}/members/{profile}/contacts/{guid}` | `ContactController@update` | `auth:sanctum`, `vet.tenant` |
| DELETE | `/v1/vets/{vet}/members/{profile}/contacts/{guid}` | `ContactController@destroy` | `auth:sanctum`, `vet.tenant` |

**Nota sobre el middleware `can:vets.*`:** El middleware `can:` de Laravel usa el guard `web` por defecto con Spatie Permission (confirmado en el proyecto). Funciona correctamente con `auth:sanctum` cuando Sanctum usa el guard `web` como `statefulGuard`. Verificar en `config/sanctum.php` que `guard` sea `web`.

**Nota sobre el parámetro `{vet}` en rutas tenant:** El parámetro se llama `vet` (string con el slug). El middleware `EnsureUserBelongsToVet` lo lee con `$request->route('vet')` y pone la instancia resuelta en `$request->attributes`. Los controllers NO lo usan como tipo en la firma — reciben `Request $request` y extraen la vet de attributes.

---

### Permisos Spatie

Agregar al `PermissionSeeder`:

| Nombre | Guard | Rol que lo recibe |
|--------|-------|-------------------|
| `vets.read` | `web` | `super-admin` (via `syncPermissions(Permission::all())`) |
| `vets.create` | `web` | `super-admin` |
| `vets.update` | `web` | `super-admin` |
| `vets.delete` | `web` | `super-admin` |
| `vets.validate` | `web` | `super-admin` |

El rol `admin` NO recibe permisos de vets. Si en el futuro se necesita, se agrega al `RoleSeeder`.

---

### Contrato de endpoints

#### GET /v1/countries
Response 200:
```json
{
  "success": true,
  "data": [
    { "guid": "uuid", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" }
  ]
}
```

#### GET /v1/countries/{guid}/document-types
Response 200:
```json
{
  "success": true,
  "data": [
    { "guid": "uuid", "name": "CUIT" },
    { "guid": "uuid", "name": "CUIL" }
  ]
}
```
Errores: `404` si el país no existe.

#### GET /v1/admin/vets
Request (query params):
```
?search=norte&validated=true&suspended=false&per_page=15&page=1
```
Response 200:
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "uuid", "name": "Vet Norte", "slug": "vet-norte",
        "tax_id": "20-12345678-9", "validated_at": "2026-06-01T00:00:00.000Z",
        "suspended_at": null, "is_active": true,
        "country": { "guid": "uuid", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" },
        "document_type": { "guid": "uuid", "name": "CUIT" }
      }
    ],
    "current_page": 1, "last_page": 3, "per_page": 15, "total": 42
  }
}
```

#### POST /v1/admin/vets
Request body:
```json
{
  "name": "Veterinaria Norte",
  "country_guid": "uuid-ar",
  "document_type_guid": "uuid-cuit",
  "tax_id": "20-12345678-9",
  "registration_number": "REG-001",
  "pdf_title": "Informe Veterinario",
  "pdf_subtitle": "Veterinaria Norte S.A."
}
```
Response 201:
```json
{
  "success": true,
  "data": { "guid": "uuid", "name": "Veterinaria Norte", "slug": "veterinaria-norte", ... },
  "message": "Veterinaria creada correctamente."
}
```
Errores:
| HTTP | Cuándo |
|------|--------|
| 422 | Validación fallida (tax_id inválido, guid inexistente, etc.) |

#### PATCH /v1/admin/vets/{guid}/validate
Response 200:
```json
{
  "success": true,
  "data": { "guid": "uuid", "validated_at": "2026-06-05T14:30:00.000Z", ... },
  "message": "Veterinaria validada correctamente."
}
```
Errores:
| HTTP | Cuándo |
|------|--------|
| 404 | Vet no encontrada |
| 422 | La vet ya está validada |

#### POST /v1/vets/{vet}/members
Request body:
```json
{
  "user_guid": "uuid-del-usuario",
  "role_guid": "uuid-del-rol-vet"
}
```
Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid-profile",
    "user": { "guid": "uuid", "name": "Juan Pérez", "email": "juan@example.com" },
    "role": { "guid": "uuid", "name": "vet" }
  },
  "message": "Miembro agregado correctamente."
}
```
Errores:
| HTTP | Cuándo |
|------|--------|
| 404 | Usuario o rol no encontrado |
| 422 | El usuario ya es miembro del tenant |

#### POST /v1/vets/{vet}/contacts (y POST /v1/vets/{vet}/members/{profile}/contacts)
Request body:
```json
{
  "type": "phone",
  "label": "Cel principal",
  "value": "+5491151234567",
  "is_primary": true,
  "use_for_alerts": true
}
```
Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid",
    "type": "phone",
    "label": "Cel principal",
    "value": "+5491151234567",
    "is_primary": true,
    "use_for_alerts": true,
    "created_at": "2026-06-05T14:30:00.000Z"
  },
  "message": "Contacto creado correctamente."
}
```
Errores:
| HTTP | Cuándo |
|------|--------|
| 422 | Formato E.164 inválido para phone/whatsapp |
| 422 | Email inválido para type=email |
| 403/404 | Perfil no pertenece al tenant (manejado por abort en resolveContactable) |

---

### Tests a generar

**Feature — `tests/Feature/Http/Controllers/V1/CountryControllerTest.php`**
- `test_index_returns_all_countries`: lista completa de países.
- `test_document_types_returns_types_for_country`: tipos de doc de AR incluyen CUIT y CUIL.
- `test_document_types_returns_404_for_unknown_guid`: guid inexistente → 404.
- `test_requires_authentication`: sin token → 401.

**Feature — `tests/Feature/Http/Controllers/V1/AdminVetControllerTest.php`**
- `test_index_requires_permission`: usuario sin `vets.read` → 403.
- `test_index_returns_paginated_vets`: superadmin con filtros → listado paginado correcto.
- `test_store_creates_vet_with_valid_data`: happy path → 201.
- `test_store_fails_with_invalid_tax_id_for_document_type`: CUIT con formato incorrecto → 422 con mensaje descriptivo.
- `test_store_fails_with_nonexistent_country_guid`: → 422.
- `test_show_returns_vet_detail`: vet existente → 200 con relations.
- `test_show_returns_404_for_unknown_guid`: → 404.
- `test_update_updates_vet_fields`: happy path → 200.
- `test_validate_sets_validated_at_and_validated_by`: validated_at se setea y validated_by es el usuario autenticado.
- `test_validate_returns_422_if_already_validated`: re-validar → 422.
- `test_suspend_sets_suspended_at`: happy path → 200.
- `test_suspend_returns_422_if_already_suspended`: → 422.
- `test_unsuspend_clears_suspended_at`: happy path → 200.
- `test_unsuspend_returns_422_if_not_suspended`: → 422.

**Feature — `tests/Feature/Http/Controllers/V1/VetControllerTest.php`**
- `test_show_returns_own_vet_data`: miembro del tenant obtiene datos de la vet.
- `test_show_requires_vet_tenant_middleware`: slug inválido → 404.
- `test_update_updates_own_vet_data`: put con campos parciales → 200.
- `test_update_validates_tax_id_format`: tax_id inválido → 422.

**Feature — `tests/Feature/Http/Controllers/V1/MemberControllerTest.php`**
- `test_index_returns_members_of_tenant`: lista de perfiles del tenant.
- `test_index_does_not_return_members_of_other_tenant`: scope de tenant correcto (crítico).
- `test_store_adds_existing_user_to_tenant`: happy path → 201.
- `test_store_fails_if_user_already_member`: → 422.
- `test_store_fails_with_non_tenant_role`: rol `super-admin` → 422.
- `test_destroy_removes_member`: → 200.
- `test_destroy_cannot_remove_self`: authenticated user intenta removerse → 403.
- `test_change_role_updates_role_id`: happy path → 200.
- `test_change_role_fails_with_non_tenant_role`: → 422.

**Feature — `tests/Feature/Http/Controllers/V1/ContactControllerTest.php`**
- `test_index_returns_contacts_of_vet`: happy path para vet contacts.
- `test_index_returns_contacts_of_profile`: happy path para member contacts.
- `test_store_creates_email_contact_for_vet`: → 201.
- `test_store_creates_phone_contact_with_e164_validation`: → 201.
- `test_store_fails_with_invalid_phone_format`: → 422.
- `test_store_sets_primary_and_clears_others`: crear primario → otros del mismo type quedan false.
- `test_profile_contact_fails_if_profile_belongs_to_different_tenant`: SEGURIDAD — guid de profile de otro tenant → 404.
- `test_update_contact_ownership_verified`: SEGURIDAD — guid de contacto de otra vet → 404.
- `test_destroy_removes_contact`: → 200.

**Unit — `tests/Unit/Services/UserProfileServiceTest.php`**
- `test_add_member_throws_if_already_exists`: RuntimeException al intentar duplicar.
- `test_find_by_guid_for_vet_returns_null_if_different_tenant`: aislamiento de tenant.
- `test_change_role_updates_role_id`: update correcto del role_id.

**Unit — `tests/Unit/Services/VetServiceTest.php`** (ampliar existente)
- `test_validate_sets_validated_at_and_validated_by`.
- `test_suspend_sets_suspended_at`.
- `test_unsuspend_clears_suspended_at`.

---

## Cambios en FRONTEND

No requiere cambios en frontend en esta iteración.

---

## Orden de implementación

1. Modificar `back/database/seeders/PermissionSeeder.php` — agregar los 5 permisos de vets.
2. Correr `php artisan db:seed --class=PermissionSeeder` (idempotente).
3. Correr `php artisan db:seed --class=RoleSeeder` para que super-admin sincronice los nuevos permisos.
4. Crear `back/app/Services/CountryService.php`.
5. Modificar `back/app/Contracts/Repositories/VetRepositoryInterface.php` — agregar `paginate`.
6. Modificar `back/app/Repositories/VetRepositoryEloquent.php` — implementar `paginate`.
7. Modificar `back/app/Services/VetService.php` — agregar `paginate`, `validate`, `suspend`, `unsuspend`.
8. Modificar `back/app/Services/ContactService.php` — agregar `findByGuidForContactable`.
9. Modificar `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php` — agregar `listForVet`, `update`, `destroy`.
10. Modificar `back/app/Repositories/UserProfileRepositoryEloquent.php` — implementar `listForVet`.
11. Crear `back/app/Services/UserProfileService.php`.
12. Crear `back/app/Http/Resources/V1/CountryResource.php`.
13. Crear `back/app/Http/Resources/V1/DocumentTypeResource.php`.
14. Crear `back/app/Http/Resources/V1/VetResource.php`.
15. Crear `back/app/Http/Resources/V1/UserProfileResource.php`.
16. Crear `back/app/Http/Resources/V1/ContactResource.php`.
17. Crear `back/app/Http/Requests/Vets/IndexVetRequest.php`.
18. Crear `back/app/Http/Requests/Vets/StoreVetRequest.php`.
19. Crear `back/app/Http/Requests/Vets/UpdateVetRequest.php`.
20. Crear `back/app/Http/Requests/Members/AssignMemberRequest.php`.
21. Crear `back/app/Http/Requests/Members/ChangeRoleMemberRequest.php`.
22. Crear `back/app/Http/Requests/Contacts/StoreContactRequest.php`.
23. Crear `back/app/Http/Requests/Contacts/UpdateContactRequest.php`.
24. Crear `back/app/Http/Controllers/V1/CountryController.php`.
25. Crear `back/app/Http/Controllers/V1/AdminVetController.php`.
26. Crear `back/app/Http/Controllers/V1/VetController.php`.
27. Crear `back/app/Http/Controllers/V1/MemberController.php`.
28. Crear `back/app/Http/Controllers/V1/ContactController.php`.
29. Crear `back/routes/api/countries.php`.
30. Crear `back/routes/api/vets.php`.
31. Correr `php artisan route:list | grep v1` para verificar que todas las rutas aparezcan correctamente.
32. Correr los feature tests de controllers.
33. Correr los unit tests de services ampliados.

---

## Riesgos y consideraciones

### Riesgo crítico 1 — Scope de tenant en MemberController::index (multi-tenant)
El método `listForVet` del repositorio filtra por `authenticatable_type = 'vet'` y `authenticatable_id = $vet->id`. Si hay un bug en este scope (ej: se omite el filtro `authenticatable_id`), se expondrían miembros de otros tenants. El test `test_index_does_not_return_members_of_other_tenant` es el guard de esto y debe ejecutarse antes de merge.

### Riesgo crítico 2 — Verificación de ownership de contacto y perfil (multi-tenant)
`ContactController::resolveContactable` y `ContactService::findByGuidForContactable` son las piezas que previenen que un usuario del tenant A opere sobre recursos del tenant B pasando guids arbitrarios. Ambos deben testearse con escenarios cross-tenant explícitos. Si alguno falla en producción, es una violación de datos entre tenants.

### Riesgo 3 — morphMap alias en ContactService::findByGuidForContactable
El campo `contacts.contactable_type` almacena `'vet'` o `'user_profile'` (alias del morphMap), no el nombre de clase. La comparación en `findByGuidForContactable` debe usar el alias. El pseudocódigo usa `array_search` sobre `Relation::morphMap()`. Si la clase no está registrada en el morphMap (ej: se agrega un nuevo contactable sin registrarlo), la comparación falla silenciosamente. Mitigación: agregar una asserción o excepción si `array_search` retorna `false`.

### Riesgo 4 — UpdateVetRequest: tax_id sin document_type_guid
Si un usuario envía solo `tax_id` sin `document_type_guid` en un PATCH/PUT, la validación de regex no aplica (se asume que el tipo no cambia). Esto puede permitir ingresar un tax_id inválido para el tipo de documento actual. Decisión tomada: aceptar este edge case para simplificar la lógica. Si se quiere ser estricto, el UpdateVetRequest debería hacer una query al modelo actual para obtener el document_type_id y aplicar la regex. Marcado como deuda técnica.

### Riesgo 5 — ContactController: firma de método con parámetros opcionales
La firma `update(Request $request, string $vet, ?string $profile = null, string $guid)` tiene un parámetro no-nullable (`$guid`) después de uno nullable (`$profile`). PHP lo permite pero puede ser confuso. En la implementación, el dev debe verificar que las rutas siempre pasen los parámetros correctos — para la ruta de vet-contacts, `profile` vendrá como `null` o simplemente no estará en la firma si se usan rutas separadas. **Recomendación de implementación:** usar rutas separadas en el archivo de rutas, no parámetro opcional — cada ruta pasa los parámetros que corresponden. En la ruta de vet contacts, `$profile` no existe; en la ruta de member contacts, `$profile` es el guid del perfil. Ambas apuntan al mismo método pero con parámetros diferentes.

**Alternativa recomendada de routing:** En lugar de usar `?string $profile = null` en la firma del controller, el dev puede verificar internamente si `$request->route('profile')` existe. Las firmas de los métodos públicos del controller quedarían:
```php
public function index(Request $request): JsonResponse
public function store(StoreContactRequest $request): JsonResponse
public function update(UpdateContactRequest $request, string $guid): JsonResponse
public function destroy(Request $request, string $guid): JsonResponse
```
Y `resolveContactable` lee `$request->route('profile')` internamente. Esto es más limpio y evita el parámetro nullable.

### Riesgo 6 — can: middleware con Sanctum
El middleware `can:vets.read` funciona si Sanctum está configurado con `statefulGuard = 'web'` y si el usuario fue autenticado con `auth:sanctum`. Verificar en `config/sanctum.php` que `guard` sea `'web'`. Si el guard de Spatie Permission y el de Sanctum son distintos, los permisos no se resuelven. Este riesgo ya existe en el proyecto (el middleware `can:` se usa en otras rutas — verificar que funcione antes de asumir que está configurado).

### Riesgo 7 — CountryService: no está en AppServiceProvider
Los services concretos no necesitan binding explícito — el container los resuelve por reflexión. Pero si en el futuro `CountryService` necesita un binding especial (ej: mock en tests), deberá agregarse al provider. Por ahora no es necesario.

### Riesgo 8 — VetService.validate vs. VetController.validate: naming conflict
El método `validate()` en `AdminVetController` llama a `$this->vetService->validate(...)`. Pero `validate()` es también un método del objeto `FormRequest` y puede generar confusión en el IDE. El nombre del método en el service es apropiado semánticamente; documentar en el controller con comentario que es el método del service, no un método de validación de Laravel.

### Deuda técnica — StoreVetRequest accede al Model directamente
El `taxIdRule` en `StoreVetRequest` hace `DocumentType::where('guid', ...)->first()` directamente sobre el modelo, sin pasar por el repositorio. Esto viola mínimamente el patrón de arquitectura. Para MVP es aceptable dado que el FormRequest no puede inyectar dependencias de forma limpia (aunque es posible con `app()->make()`). Si se desea consistencia estricta, el dev puede inyectar `DocumentTypeRepositoryInterface` usando `app()->make()` dentro del closure. Marcado como deuda técnica menor.

### Impacto multi-tenant
Todos los endpoints bajo `/v1/vets/{vet}/...` están protegidos por `vet.tenant`. Las queries dentro de esos endpoints SIEMPRE deben usar la vet resuelta por el middleware (`$request->attributes->get('current_vet')`) como scope. No hay endpoints en esta iteración que accedan a datos de múltiples tenants simultáneamente.

### Impacto multi-país
No hay lógica hardcodeada de un país específico en esta capa HTTP. La validación de `tax_id` usa el `validation_regex` del `DocumentType`, que es por país. Al agregar nuevos países al `CountrySeeder`, los FormRequests funcionan automáticamente.

---

## Pendientes / fuera de alcance

- **Gestión de permisos por rol de tenant**: los roles de tenant (`vet`, `vet-assistant`, etc.) no tienen permisos Spatie en esta iteración. Los endpoints del panel tenant son accesibles por cualquier miembro del tenant (el `vet.tenant` middleware solo verifica pertenencia). El control granular de qué rol puede hacer qué dentro del tenant es una iteración posterior.
- **Filtros de permisos en el panel tenant**: ej. solo el rol `vet` puede actualizar datos de la vet; `vet-assistant` es solo lectura. Requiere permisos Spatie adicionales o guards de rol dentro del controller.
- **Upload de logo**: `logo_path` se trata como string en esta iteración. El upload real de archivo (multipart, storage) queda para una iteración específica de gestión de branding.
- **Invitación de usuarios al tenant por email**: el `POST /members` actual requiere que el usuario ya exista en el sistema. El flujo de "invitar por email a un usuario que no existe" es una feature separada.
- **Autorización granular dentro del tenant por rol**: el middleware `vet.tenant` solo verifica pertenencia. No verifica si el rol del miembro tiene permiso para la acción específica (ej: solo `client-owner` puede ver facturación). Iteración posterior.
- **Paginación en listado de miembros y contactos**: actualmente devuelve `Collection` completa. Si un tenant puede tener muchos miembros o contactos, se deberá paginar. Para MVP con pocos miembros por tenant, es aceptable.
- **DELETE /v1/admin/vets/{guid}**: el brief menciona permiso `vets.delete` pero no define un endpoint de eliminación de vet. No se incluye en esta iteración. Si se necesita, requiere definir el comportamiento en cascada (borrar todos los UserProfiles, contactos, animales, protocolos del tenant).
