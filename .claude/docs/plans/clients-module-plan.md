# Plan técnico: Módulo Clients — Endpoints de lookup, link y owners

## Input procesado

Brief informal detallado provisto en el chat (2026-06-07).
Describe los 4 endpoints nuevos a agregar sobre el módulo Client ya implementado:
`GET /lookup`, `POST /{guid}/link`, `GET /{guid}/owners`, `POST /{guid}/owners`.

---

## Estado actual del sistema (verificado en código)

El módulo base Client + Establishment está **completamente implementado** según el plan anterior
`client-establishment-backend-plan.md`. Lo que sigue son exclusivamente los 4 endpoints nuevos.

Archivos que ya existen y NO deben tocarse salvo lo indicado en este plan:

- `back/app/Models/Client.php` — completo
- `back/app/Models/UserProfile.php` — completo
- `back/app/Models/Vet.php` — completo con `clients()` BelongsToMany
- `back/app/Repositories/ClientRepositoryEloquent.php` — tiene todos los métodos base
- `back/app/Contracts/Repositories/ClientRepositoryInterface.php` — tiene todos los contratos base
- `back/app/Services/ClientService.php` — tiene `paginate`, `create`, `findByGuidForVet`, `update`, `detach`
- `back/app/Services/UserProfileService.php` — tiene `addMember`, `findByGuidForVet`, etc.
- `back/app/Http/Controllers/V1/ClientController.php` — tiene `index`, `store`, `show`, `update`, `destroy`
- `back/routes/api/clients.php` — tiene las 13 rutas base
- `back/app/Providers/AppServiceProvider.php` — bindings y morphMap ya registrados
- `back/database/seeders/PermissionSeeder.php` — permisos clients.* y establishments.* ya seeded

---

## Resumen ejecutivo

Se agregan 4 endpoints sobre el módulo Client ya implementado. El más complejo es `POST /owners`,
que implementa el patrón "buscar usuario por email, crear si no existe, crear UserProfile con
authenticatable Client, encolar job de invitación". Los otros tres son lookups/pivots sin lógica
compleja. Se crean: 1 Job de invitación, 1 Mailable de invitación, 3 métodos nuevos en el
repositorio de clients, 3 métodos nuevos en ClientService, 2 métodos nuevos en
UserProfileService, 1 FormRequest, 1 controller nuevo (ClientOwnerController), y se extienden
las rutas y permisos existentes.

---

## Decisiones tomadas

### DEC-01 — ClientOwnerController separado del ClientController

**Decisión:** Los endpoints de owners van en `back/app/Http/Controllers/V1/ClientOwnerController.php`,
no en `ClientController`. El `ClientController` ya tiene 5 métodos; agregar `indexOwners` y
`storeOwner` lo haría demasiado grande y mezclaría responsabilidades distintas (CRUD del client
vs. gestión de su personal).

**Justificación:** El patrón del proyecto es un controller por recurso. Los owners son un
sub-recurso del client con lógica de negocio diferenciada (lookup de User, creación de User,
job de invitación). La separación también permite asignar permisos distintos sin contaminar
los del CRUD de clients.

**Alternativa descartada:** Agregar `indexOwners` y `storeOwner` directamente en `ClientController`
— va en contra de Single Responsibility y haría el controller difícil de testear.

---

### DEC-02 — lookup busca por tax_id en toda la tabla clients (sin scope de vet)

**Decisión:** `GET /v1/vets/{vet}/clients/lookup?tax_id=...` ejecuta una query sin filtro de
`client_vet`. Retorna el client si existe globalmente, indicando si ya está vinculado a esta
vet o no. El campo `already_linked` en la respuesta le dice al frontend si mostrar "link" o
"ya es cliente".

**Justificación:** El propósito del endpoint es descubrir si un client existe en el sistema
antes de crearlo duplicado. Si solo buscara en la vet actual, sería equivalente a un filtro
en el index. La búsqueda global es la razón de ser del endpoint.

**Seguridad:** El endpoint retorna datos mínimos del client encontrado (guid, name, tax_id,
country, document_type). No expone datos de otras vets vinculadas al client. El hecho de que
el client exista en el sistema no es información sensible — solo el contenido de sus datos.

**Alternativa descartada:** Retornar solo `{ found: true }` sin datos del client — el frontend
necesita mostrar nombre y tax_id para que el usuario confirme que es el mismo cliente antes de
linkear.

---

### DEC-03 — tax_id en lookup es búsqueda exacta, no parcial

**Decisión:** La búsqueda por `tax_id` en `lookup` usa igualdad exacta (`=`), no LIKE.

**Justificación:** `tax_id` es un identificador fiscal con formato definido (CUIT, RFC, etc.).
Si el usuario busca "20-12345678-9", espera encontrar exactamente ese cliente, no todos los
que contengan esa cadena. Una búsqueda parcial generaría ambigüedad y múltiples resultados
para un endpoint que retorna un único cliente.

**Caso borde:** Si hay múltiples clients con el mismo `tax_id` (la tabla no tiene unique
constraint por decisión DEC-05 del plan anterior), el endpoint retorna el primero encontrado
por `created_at` desc. Se documenta en Riesgos.

**Alternativa descartada:** Retornar una lista de matches — rompería el contrato de respuesta
simple `{ found, client }` y complicaría el flujo UX del frontend.

---

### DEC-04 — link no modifica el Client, solo crea el pivot

**Decisión:** `POST /v1/vets/{vet}/clients/{guid}/link` solo ejecuta `$vet->clients()->attach($client->id)`
si el pivot no existe. Retorna 409 si ya está vinculado. No hace ningún `update` sobre el
modelo `Client`.

**Justificación:** La spec es explícita: "solo crea el pivot, no modifica el Client". El
aislamiento multi-tenant es fundamental — el cliente pertenece a múltiples vets y ninguna
puede modificar datos del cliente de otra.

**Alternativa descartada:** Permitir re-attach silencioso (sin error) — oculta bugs del
frontend que llama al endpoint dos veces y da una UX confusa al usuario.

---

### DEC-05 — owners son UserProfiles con authenticatable_type='client'

**Decisión:** Un `client-owner` es un `UserProfile` donde `authenticatable_type = 'client'`
y `authenticatable_id = $client->id`. El `role_id` apunta al rol `client-owner` en la tabla
`roles`. Esta estructura es consistente con el morphMap existente y con cómo los vet-members
usan `authenticatable_type = 'vet'`.

**Justificación:** `UserProfile` ya es el modelo polimórfico de "perfil dentro de un contexto".
El morphMap ya registra `'client' => Client::class`. La estructura `(user_id, authenticatable_type,
authenticatable_id, role_id)` soporta que un mismo `User` sea owner de múltiples clients sin
colisión.

**Alternativa descartada:** Crear un modelo `ClientOwner` separado — duplica la estructura de
UserProfile y rompe la consistencia del sistema de perfiles.

---

### DEC-06 — Creación de User en POST /owners usa password temporal y email no verificado

**Decisión:** Si el email no corresponde a ningún User existente, se crea el User con:
- `password` = `Hash::make(Str::random(32))` (temporal, no usable)
- `email_verified_at` = null (no verificado)
- `first_name`, `last_name` del body del request
- `name` = `first_name . ' ' . last_name`

Luego se encola `SendClientOwnerInvitationJob` que envía un email con un link de onboarding
(set-password + verify-account). El job usa el mismo patrón que `VerifyAccountEmail`.

**Justificación:** El User debe existir en la tabla para poder crear el UserProfile. La
creación silenciosa con password temporal es el patrón estándar de "invitación por email" en
sistemas SaaS. El usuario no puede loguearse hasta verificar y setear su password, lo que
garantiza seguridad.

**Alternativa descartada:** Crear el User en un estado "pendiente" con una tabla separada —
añade complejidad innecesaria cuando la tabla `users` con `email_verified_at = null` ya
representa ese estado.

---

### DEC-07 — POST /owners retorna 422 si el User ya tiene un UserProfile como client-owner de ESTE client

**Decisión:** Si el User ya existe Y ya tiene un UserProfile con `authenticatable_type='client'`,
`authenticatable_id=$client->id` y role `client-owner`, se retorna 422 "Este usuario ya es
owner de este cliente." No se retorna 409 (que es para el caso de link de vet).

**Justificación:** Consistencia con el patrón de `UserProfileService::addMember()` que lanza
`RuntimeException` en caso de duplicado, que el controller convierte en 422. El error 422 es
más apropiado aquí porque la duplicación es un error de validación de negocio, no un conflicto
de estado del recurso.

**Alternativa descartada:** Retornar el UserProfile existente silenciosamente (idempotente)
— oculta un bug del frontend y hace imposible distinguir si el owner fue creado ahora o ya
existía.

---

### DEC-08 — GET /owners lista SOLO los UserProfiles de este client (todas las vets los ven)

**Decisión:** `GET /v1/vets/{vet}/clients/{guid}/owners` primero verifica que el client está
vinculado a la vet (seguridad tenant), luego retorna `UserProfile::where('authenticatable_type',
'client')->where('authenticatable_id', $client->id)->with(['user', 'role'])->get()`. No filtra
por vet.

**Justificación:** La spec dice "los owners son visibles para todas las vets del client". El
scope tenant se aplica al acceso al client (debe pertenecer a la vet), pero los owners del
client son compartidos entre todas las vets vinculadas. Esto es correcto: si Vet A invita a
un owner, Vet B también lo ve.

**Alternativa descartada:** Filtrar owners por la vet que los creó — rompería la prop de que
los owners son del client, no de una vet particular.

---

### DEC-09 — Nuevos permisos: clients.owners.read y clients.owners.create

**Decisión:** Se agregan 2 permisos nuevos: `clients.owners.read` y `clients.owners.create`.
La ruta de link usa `clients.create` (ya existente) porque linkear un client es equivalente a
crearlo para la vet. La ruta de lookup no requiere permiso adicional — usa `clients.read`.

**Justificación:** owners es un sub-recurso con semántica distinta al CRUD de clients. Un
usuario puede tener permiso de leer clients sin poder ver o crear owners (que involucra datos
de Users). El permiso separado permite granularidad fina.

**Alternativa descartada:** Reutilizar `clients.update` para owners — semánticamente incorrecto
(crear un owner no es "actualizar" el client) y viola el principio de menor privilegio.

---

### DEC-10 — El Job de invitación envía email con link de set-password, no código

**Decisión:** `SendClientOwnerInvitationJob` genera un `verification_link_token` en el User y
envía un email con el link `{frontend_url}/invitacion?token={token}&email={email}`. El
frontend recibe el token y presenta la pantalla de set-password + verificación de cuenta. El
token expira en 72 horas (configurable).

**Justificación:** El patrón de código de 6 dígitos (`VerifyAccountEmail`) es para usuarios
que se registran ellos mismos y tienen motivación para ingresar el código. Un owner invitado
no sabe que tiene una cuenta — el link directo tiene mejor UX y menor fricción. 72 horas da
tiempo suficiente para que el propietario procese el email sin urgencia.

**Alternativa descartada:** Enviar código de 6 dígitos — peor UX para invitaciones, el usuario
invitado no sabe qué hacer con un código si no fue él quien inició el registro.

---

## Cambios en BACKEND

### Archivos a crear

---

#### `back/app/Jobs/SendClientOwnerInvitationJob.php`

**Propósito:** Job encolable que envía el email de invitación a un owner de client recién
creado. Se ejecuta de forma asíncrona para no bloquear la respuesta HTTP del POST /owners.

**Firma principal:**
```php
namespace App\Jobs;

use App\Mail\ClientOwnerInvitationEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendClientOwnerInvitationJob implements ShouldQueue
{
    use QueueableTrait, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $userId,
        public readonly string $clientName,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            Log::warning('SendClientOwnerInvitationJob: usuario no encontrado', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // Regenerar token si expiró
        if (!$user->verification_link_token || now()->isAfter($user->verification_link_expires_at)) {
            $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);
            $user->verification_link_token   = \Illuminate\Support\Str::random(64);
            $user->verification_link_expires_at = now()->addHours($expirationHours);
            $user->save();
        }

        try {
            Mail::to($user->email)->send(
                new ClientOwnerInvitationEmail($user, $this->clientName)
            );
        } catch (\Exception $e) {
            Log::error('SendClientOwnerInvitationJob: fallo al enviar email', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
            throw $e; // relanza para que el job reintente
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendClientOwnerInvitationJob falló definitivamente', [
            'user_id' => $this->userId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
```

**Dependencias:** `User::find()` directo (el modelo en el job, no el repositorio — patrón del
`ProcessExportJob` existente). `ClientOwnerInvitationEmail`.

**Nota de implementación:** El job recibe `$userId` (int interno) y `$clientName` (string). No
serializa el modelo `User` completo para evitar problemas de consistencia si el modelo cambia
entre el dispatch y la ejecución. El `clientName` se pasa para personalizar el email ("Te
invitamos a gestionar [ClientName]").

---

#### `back/app/Mail/ClientOwnerInvitationEmail.php`

**Propósito:** Mailable que notifica al invitado que tiene acceso como owner de un client en SAV.
Incluye el link de set-password + verificación de cuenta.

**Firma principal:**
```php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientOwnerInvitationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $clientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Acceso a ' . $this->clientName . ' en ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $frontendUrl      = rtrim(config('app.frontend_url'), '/');
        $expirationHours  = (int) config('auth.invitation_link_expiration_hours', 72);
        $invitationUrl    = $frontendUrl
            . '/invitacion'
            . '?token=' . urlencode($this->user->verification_link_token)
            . '&email=' . urlencode($this->user->email);

        return new Content(
            view: 'emails.client-owner-invitation',
            with: [
                'firstName'       => $this->user->first_name,
                'clientName'      => $this->clientName,
                'invitationUrl'   => $invitationUrl,
                'expirationHours' => $expirationHours,
            ],
        );
    }
}
```

**Vista a crear:** `back/resources/views/emails/client-owner-invitation.blade.php`
Misma estructura que `back/resources/views/emails/verify-account.blade.php`. El dev puede
copiar y adaptar. Contenido mínimo requerido:
- Saludo con `$firstName`
- Mensaje: "Fuiste invitado/a a gestionar [clientName] en SAV."
- Botón/link con `$invitationUrl`
- Nota: "Este link expira en `$expirationHours` horas."

**Config requerida:** `config('app.frontend_url')` debe estar definida en `.env` como
`FRONTEND_URL`. Si no existe, agregar a `config/app.php`:
```php
'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
```

---

#### `back/app/Http/Requests/Clients/StoreLinkRequest.php`

**Propósito:** Validación del endpoint POST /link. No tiene body (el guid del client viene en
la ruta), pero se crea el FormRequest para mantener el patrón del proyecto.

```php
namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
        // No hay body — el guid del client viene como parámetro de ruta.
        // La validación de existencia del client se hace en el controller via ClientService.
    }
}
```

**Nota:** Un FormRequest vacío puede parecer innecesario, pero mantiene la consistencia del
patrón (todo endpoint de escritura tiene FormRequest) y es el lugar correcto para agregar
validaciones futuras (ej: un campo `notes` al vincular).

---

#### `back/app/Http/Requests/Clients/StoreOwnerRequest.php`

**Propósito:** Validación del body de POST /owners.

```php
namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'      => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'      => 'El email es obligatorio.',
            'email.email'         => 'El email debe tener un formato válido.',
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required'  => 'El apellido es obligatorio.',
        ];
    }
}
```

**Nota sobre first_name/last_name:** Son requeridos aunque el User ya exista, porque en ese
caso se ignoran (no se sobreescriben datos del User existente). La validación no puede saber
en tiempo de FormRequest si el User existe; esa lógica está en el Service. Si en el futuro se
quisiera que sean opcionales cuando el User ya existe, se puede cambiar a `sometimes`.

---

#### `back/app/Http/Controllers/V1/ClientOwnerController.php`

**Propósito:** Controller delgado para gestión de owners de un client. Maneja los endpoints
GET /owners y POST /owners. El acceso al client siempre está scoped al tenant actual.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreOwnerRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ClientService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientOwnerController extends Controller
{
    public function __construct(
        private ClientService      $clientService,
        private UserProfileService $userProfileService,
    ) {}

    /**
     * Lista los UserProfiles con role client-owner del Client dado.
     * Verifica primero que el client pertenece al tenant actual.
     */
    public function index(Request $request, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $owners = $this->userProfileService->listOwnersForClient($client);

            return $this->makeSuccess(UserProfileResource::collection($owners));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Crea un client-owner para el Client dado.
     * Si el User no existe, lo crea y encola el job de invitación.
     * Verifica que el client pertenece al tenant actual antes de operar.
     */
    public function store(StoreOwnerRequest $request, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->addOwnerToClient(
                $client,
                $request->validated(),
            );

            $profile->load(['user', 'role']);

            return $this->makeSuccess(
                new UserProfileResource($profile),
                'Owner creado correctamente.',
                201,
            );
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

**Dependencias inyectadas:** `ClientService`, `UserProfileService`.

---

### Archivos a modificar

---

#### `back/app/Contracts/Repositories/ClientRepositoryInterface.php`

**Cambio:** Agregar 2 métodos nuevos al final de la interface.

**Antes (última línea de métodos):**
```php
public function detachFromVet(Client $client, Vet $vet): void;
```

**Después (agregar):**
```php
/**
 * Busca un client globalmente por tax_id exacto, sin filtro de vet.
 * Si hay múltiples con el mismo tax_id, retorna el más reciente.
 */
public function findByTaxId(string $taxId): ?Client;

/**
 * Verifica si un client ya está vinculado a una vet (fila en client_vet).
 */
public function isLinkedToVet(Client $client, Vet $vet): bool;

/**
 * Vincula un client existente a una vet (crea fila en client_vet).
 * El caller debe verificar que no esté ya vinculado antes de llamar este método.
 */
public function attachToVet(Client $client, Vet $vet): void;
```

**Imports a agregar en la interface (si no están):** ninguno nuevo — `Client` y `Vet` ya
están importados.

---

#### `back/app/Repositories/ClientRepositoryEloquent.php`

**Cambio:** Implementar los 3 métodos nuevos declarados en la interface.

**Agregar al final de la clase:**

```php
public function findByTaxId(string $taxId): ?Client
{
    return $this->newQuery()
        ->where('tax_id', $taxId)
        ->latest()
        ->first();
}

public function isLinkedToVet(Client $client, Vet $vet): bool
{
    return $vet->clients()
        ->where('clients.id', $client->id)
        ->exists();
}

public function attachToVet(Client $client, Vet $vet): void
{
    $vet->clients()->attach($client->id);
}
```

**Nota de implementación:** `isLinkedToVet` usa `$vet->clients()->where('clients.id', ...)` y
no `$vet->clients()->find($client->id)` para evitar cargar el modelo completo cuando solo
necesitamos saber si existe el pivot.

---

#### `back/app/Services/ClientService.php`

**Cambio:** Agregar 3 métodos nuevos.

**Agregar después de `detach()`:**

```php
/**
 * Busca un client globalmente por tax_id.
 * Retorna el client si existe + flag de si ya está vinculado a la vet dada.
 *
 * @return array{ found: bool, client: ?Client, already_linked: bool }
 */
public function lookupByTaxId(string $taxId, Vet $vet): array
{
    $client = $this->clientRepository->findByTaxId($taxId);

    if (!$client) {
        return ['found' => false, 'client' => null, 'already_linked' => false];
    }

    $alreadyLinked = $this->clientRepository->isLinkedToVet($client, $vet);

    return [
        'found'          => true,
        'client'         => $client,
        'already_linked' => $alreadyLinked,
    ];
}

/**
 * Vincula un client existente a una vet.
 * Lanza RuntimeException si ya está vinculado.
 */
public function linkToVet(Client $client, Vet $vet): void
{
    if ($this->clientRepository->isLinkedToVet($client, $vet)) {
        throw new \RuntimeException('Este cliente ya está vinculado a esta veterinaria.');
    }

    $this->clientRepository->attachToVet($client, $vet);
}
```

**Nota:** `lookupByTaxId` retorna un array tipado. El controller lo destructura directamente.
No se crea un DTO porque el patrón del proyecto usa arrays para shapes simples de este tipo
(ver `AuthService::login()` que retorna un array con múltiples claves).

---

#### `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php`

**Cambio:** Agregar 2 métodos nuevos.

**Antes (última línea de métodos):**
```php
public function destroy(UserProfile $profile): bool|null;
```

**Después (agregar):**
```php
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lista los UserProfiles con role 'client-owner' de un Client dado.
 */
public function listOwnersForClient(Client $client): Collection;

/**
 * Busca un UserProfile de tipo 'client' para un User y Client específicos.
 * Retorna null si no existe.
 */
public function findForUserAndClient(User $user, Client $client): ?UserProfile;
```

**Nota de imports:** Verificar que `Client` esté importado en el archivo. Si no, agregar
`use App\Models\Client;`.

---

#### `back/app/Repositories/UserProfileRepositoryEloquent.php`

**Cambio:** Implementar los 2 métodos nuevos.

**Agregar al final de la clase:**

```php
public function listOwnersForClient(Client $client): Collection
{
    return $this->newQuery()
        ->with(['user', 'role'])
        ->whereHas('role', fn ($q) => $q->where('name', 'client-owner'))
        ->where('authenticatable_type', 'client')
        ->where('authenticatable_id', $client->id)
        ->get();
}

public function findForUserAndClient(User $user, Client $client): ?UserProfile
{
    return $this->newQuery()
        ->where('user_id', $user->id)
        ->where('authenticatable_type', 'client')
        ->where('authenticatable_id', $client->id)
        ->first();
}
```

**Nota de implementación:** `listOwnersForClient` usa `whereHas('role', ...)` para filtrar
por nombre de rol. Esto genera un subquery en la tabla `roles`. El índice de `role_id` en
`user_profiles` hace que sea eficiente. Alternativa más performante para producción: guardar
el `role_id` del rol `client-owner` en caché y filtrar por ID directo. Pero para el volumen
actual es aceptable.

**Imports a agregar:**
```php
use App\Models\Client;
use App\Models\User;
```

---

#### `back/app/Services/UserProfileService.php`

**Cambio:** Agregar 2 métodos públicos y actualizar los imports.

**Agregar imports (si no están):**
```php
use App\Models\Client;
use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Jobs\SendClientOwnerInvitationJob;
```

**Agregar métodos al final de la clase:**

```php
/**
 * Lista los owners (UserProfiles con role client-owner) de un Client dado.
 */
public function listOwnersForClient(Client $client): Collection
{
    return $this->userProfileRepository->listOwnersForClient($client);
}

/**
 * Crea un client-owner para el Client.
 * 
 * Flujo:
 *   1. Buscar User por email.
 *   2a. Si no existe: crear User con password temporal + encolar job de invitación.
 *   2b. Si existe: usar ese User (sin modificarlo, sin reenviar invitación).
 *   3. Verificar que el User no sea ya owner de este Client.
 *   4. Resolver el rol 'client-owner' de la tabla roles.
 *   5. Crear UserProfile(authenticatable_type='client', authenticatable_id=$client->id).
 *   6. Todo dentro de una transacción DB.
 *
 * Lanza RuntimeException si el User ya es owner del Client.
 *
 * @param  Client $client   Client al que se agrega el owner
 * @param  array  $data     Datos validados: { email, first_name, last_name }
 */
public function addOwnerToClient(Client $client, array $data): UserProfile
{
    return DB::transaction(function () use ($client, $data) {
        $user     = $this->userRepository->findByEmail($data['email']);
        $isNewUser = false;

        if (!$user) {
            // Usuario nuevo: crear con password temporal y sin verificar
            $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);

            $user = $this->userRepository->create([
                'first_name'                  => $data['first_name'],
                'last_name'                   => $data['last_name'],
                'name'                        => $data['first_name'] . ' ' . $data['last_name'],
                'email'                       => $data['email'],
                'password'                    => Hash::make(Str::random(32)),
                'email_verified_at'           => null,
                'verification_link_token'     => Str::random(64),
                'verification_link_expires_at'=> now()->addHours($expirationHours),
            ]);

            $isNewUser = true;
        }

        // Verificar duplicado: este User ya es owner de este Client
        $existing = $this->userProfileRepository->findForUserAndClient($user, $client);
        if ($existing) {
            throw new \RuntimeException('Este usuario ya es owner de este cliente.');
        }

        // Resolver rol 'client-owner' desde la tabla roles
        $role = $this->roleRepository->findByName('client-owner');
        if (!$role) {
            throw new \RuntimeException('El rol client-owner no existe en el sistema.');
        }

        // Crear el UserProfile
        $profile = $this->userProfileRepository->create([
            'user_id'              => $user->id,
            'authenticatable_type' => 'client',
            'authenticatable_id'   => $client->id,
            'role_id'              => $role->id,
        ]);

        // Encolar job de invitación solo si el usuario es nuevo
        if ($isNewUser) {
            SendClientOwnerInvitationJob::dispatch($user->id, $client->name);
        }

        return $profile;
    });
}
```

**Dependencias que ya están inyectadas en el constructor:** `UserProfileRepositoryInterface`,
`UserRepositoryInterface`, `RoleRepositoryInterface`.

---

#### `back/app/Contracts/Repositories/RoleRepositoryInterface.php`

**Cambio:** Agregar 1 método nuevo.

**Antes (última línea de métodos):**
```php
public function exportQuery(QueryCriterion ...$criteria): Builder;
```

**Después (agregar):**
```php
/**
 * Busca un rol por nombre exacto. Retorna null si no existe.
 */
public function findByName(string $name): ?Role;
```

---

#### `back/app/Repositories/RoleRepositoryEloquent.php`

**Cambio:** Implementar `findByName`.

**Agregar al final de la clase:**

```php
public function findByName(string $name): ?Role
{
    return $this->newQuery()->where('name', $name)->first();
}
```

---

#### `back/app/Http/Controllers/V1/ClientController.php`

**Cambio:** Agregar los métodos `lookup` y `link`.

**Agregar imports:**
```php
use App\Http\Requests\Clients\StoreLinkRequest;
```

**Agregar `ClientService` ya está inyectado — no tocar el constructor.**

**Agregar métodos al final de la clase:**

```php
/**
 * Busca un client globalmente por tax_id.
 * No requiere que el client esté vinculado al tenant actual.
 * Retorna found=true/false + datos del client si existe + already_linked.
 */
public function lookup(Request $request): JsonResponse
{
    try {
        $taxId = $request->query('tax_id');

        if (empty($taxId)) {
            return $this->makeError(
                ['tax_id' => ['El parámetro tax_id es requerido.']],
                'Parámetros inválidos.',
                422,
            );
        }

        $vet    = $request->attributes->get('current_vet');
        $result = $this->clientService->lookupByTaxId($taxId, $vet);

        if (!$result['found']) {
            return $this->makeSuccess(['found' => false, 'client' => null]);
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

/**
 * Vincula un client existente (identificado por guid en ruta) al tenant actual.
 * Retorna 422 si ya está vinculado.
 */
public function link(StoreLinkRequest $request, string $guid): JsonResponse
{
    try {
        $vet    = $request->attributes->get('current_vet');
        $client = $this->clientService->findByGuid($guid);

        if (!$client) {
            return $this->makeNotFound('Cliente no encontrado.');
        }

        $this->clientService->linkToVet($client, $vet);

        $client->load(['country', 'documentType']);

        return $this->makeSuccess(
            new ClientResource($client),
            'Cliente vinculado correctamente.',
            201,
        );
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Nota crítica sobre `link`:** El método `findByGuid` busca globalmente (sin scope de vet),
que es lo correcto para el flujo de link. Se usa `$this->clientService->findByGuid($guid)` que
llama a `ClientRepositoryInterface::findByGuid()` ya implementado. Distinguir de
`findByGuidForVet` es intencional y fundamental para la seguridad del flujo.

**Agregar `findByGuid` a `ClientService`:**

En `back/app/Services/ClientService.php`, agregar:

```php
/**
 * Busca un client por guid globalmente (sin scope de vet).
 * Usar solo para flujos de lookup/link donde el client puede no estar vinculado aún.
 */
public function findByGuid(string $guid): ?Client
{
    return $this->clientRepository->findByGuid($guid);
}
```

---

#### `back/routes/api/clients.php`

**Cambio:** Agregar las 4 rutas nuevas. Las rutas de `lookup` y `link` van en el grupo de
`clients`. Las rutas de `owners` van en un sub-grupo anidado bajo `/{guid}`.

**Antes:**
```php
Route::prefix('clients')->group(function () {
    Route::get('/',          [ClientController::class, 'index'])->middleware('can:clients.read');
    Route::post('/',         [ClientController::class, 'store'])->middleware('can:clients.create');
    Route::get('/{guid}',    [ClientController::class, 'show'])->middleware('can:clients.read');
    Route::put('/{guid}',    [ClientController::class, 'update'])->middleware('can:clients.update');
    Route::delete('/{guid}', [ClientController::class, 'destroy'])->middleware('can:clients.delete');

    // Contactos de un client
    Route::prefix('/{client}/contacts')->group(function () { ... });
    // Establecimientos de un client
    Route::prefix('/{client}/establishments')->group(function () { ... });
});
```

**Después — agregar estas rutas en el grupo de clients, antes de los grupos anidados:**

```php
use App\Http\Controllers\V1\ClientOwnerController;

// Lookup global por tax_id (búsqueda antes de crear)
Route::get('/lookup', [ClientController::class, 'lookup'])->middleware('can:clients.read');

// Vincular client existente al tenant
Route::post('/{guid}/link', [ClientController::class, 'link'])->middleware('can:clients.create');

// Owners de un client
Route::prefix('/{guid}/owners')->group(function () {
    Route::get('/',  [ClientOwnerController::class, 'index'])->middleware('can:clients.owners.read');
    Route::post('/', [ClientOwnerController::class, 'store'])->middleware('can:clients.owners.create');
});
```

**Advertencia de orden de rutas:** La ruta `GET /clients/lookup` DEBE registrarse ANTES de
`GET /clients/{guid}` para que Laravel no interprete "lookup" como un guid. En el archivo
actual, las rutas se definen en orden, por lo que `/lookup` debe ir antes de `/{guid}`.
Verificar con `php artisan route:list` que no haya colisión.

---

#### `back/database/seeders/PermissionSeeder.php`

**Cambio:** Agregar los 2 permisos nuevos en el array `$permissions`.

**Antes (últimas líneas del array):**
```php
'establishments.read',
'establishments.create',
'establishments.update',
'establishments.delete',
```

**Después:**
```php
'establishments.read',
'establishments.create',
'establishments.update',
'establishments.delete',
'clients.owners.read',
'clients.owners.create',
```

---

### Rutas API (nuevas)

Todas bajo `prefix v1/vets/{vet}`, middleware base `auth:sanctum` + `vet.tenant`.

| Método | Path completo | Controller@Action | Permiso requerido |
|--------|---------------|-------------------|-------------------|
| GET | `/v1/vets/{vet}/clients/lookup` | `ClientController@lookup` | `clients.read` |
| POST | `/v1/vets/{vet}/clients/{guid}/link` | `ClientController@link` | `clients.create` |
| GET | `/v1/vets/{vet}/clients/{guid}/owners` | `ClientOwnerController@index` | `clients.owners.read` |
| POST | `/v1/vets/{vet}/clients/{guid}/owners` | `ClientOwnerController@store` | `clients.owners.create` |

---

### Permisos Spatie

| Permiso | Guard | Roles que lo reciben ahora |
|---------|-------|---------------------------|
| `clients.owners.read` | `web` | `super-admin` (via `Permission::all()`) |
| `clients.owners.create` | `web` | `super-admin` |

Los roles tenant (`vet`, `vet-assistant`, `client-owner`, etc.) no reciben estos permisos en
esta iteración. La asignación granular de permisos a roles tenant es una tarea separada que
debe definirse con el negocio.

---

### Contratos de los endpoints nuevos

#### GET /v1/vets/{vet}/clients/lookup?tax_id=...

Request (query param):
```
tax_id: string — requerido, búsqueda exacta
```

Response 200 (no encontrado):
```json
{
  "success": true,
  "data": {
    "found": false,
    "client": null
  }
}
```

Response 200 (encontrado, no vinculado):
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": false,
    "client": {
      "guid": "uuid",
      "name": "Razón Social S.A.",
      "tax_id": "20-12345678-9",
      "address": null,
      "city": null,
      "state": null,
      "zip_code": null,
      "country": { "guid": "uuid", "name": "Argentina", "iso_code": "AR" },
      "document_type": { "guid": "uuid", "name": "CUIT" },
      "contacts": [],
      "establishments": [],
      "created_at": "2026-06-05T00:00:00.000000Z"
    }
  }
}
```

Response 200 (encontrado, ya vinculado):
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": true,
    "client": { /* mismo shape */ }
  }
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 422 | `tax_id` no viene en la query |
| 403 | Sin permiso `clients.read` |
| 403 | Usuario no pertenece al tenant |

---

#### POST /v1/vets/{vet}/clients/{guid}/link

Request body: vacío (el guid del client va en la URL).

Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid",
    "name": "Razón Social S.A.",
    "tax_id": "20-12345678-9",
    "country": { "guid": "uuid", "name": "Argentina" },
    "document_type": { "guid": "uuid", "name": "CUIT" },
    "contacts": [],
    "establishments": [],
    "created_at": "2026-06-05T00:00:00.000000Z"
  },
  "message": "Cliente vinculado correctamente."
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | El guid no corresponde a ningún Client en el sistema |
| 422 | El client ya está vinculado a esta vet |
| 403 | Sin permiso `clients.create` |

---

#### GET /v1/vets/{vet}/clients/{guid}/owners

Response 200:
```json
{
  "success": true,
  "data": [
    {
      "guid": "uuid-del-profile",
      "user": {
        "guid": "uuid-del-user",
        "name": "Juan Pérez",
        "first_name": "Juan",
        "last_name": "Pérez",
        "email": "juan@ejemplo.com"
      },
      "role": {
        "guid": "uuid-del-rol",
        "name": "client-owner"
      },
      "contacts": [],
      "created_at": "2026-06-07T00:00:00.000000Z"
    }
  ]
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | El client no existe o no pertenece al tenant |
| 403 | Sin permiso `clients.owners.read` |

---

#### POST /v1/vets/{vet}/clients/{guid}/owners

Request body:
```json
{
  "email": "required|string|email|max:255",
  "first_name": "required|string|max:100",
  "last_name": "required|string|max:100"
}
```

Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid-del-profile",
    "user": {
      "guid": "uuid-del-user",
      "name": "María López",
      "first_name": "María",
      "last_name": "López",
      "email": "maria@ejemplo.com"
    },
    "role": {
      "guid": "uuid-del-rol",
      "name": "client-owner"
    },
    "contacts": [],
    "created_at": "2026-06-07T00:00:00.000000Z"
  },
  "message": "Owner creado correctamente."
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | El client no existe o no pertenece al tenant |
| 422 | El user ya es owner de este client |
| 422 | El rol `client-owner` no existe en DB (error de setup) |
| 422 | Validación del body (email inválido, first_name vacío, etc.) |
| 403 | Sin permiso `clients.owners.create` |

---

## Cambios en FRONTEND

No requiere cambios en frontend en esta iteración. El plan cubre exclusivamente el backend.
El contrato de API está definido arriba para que el agente de frontend lo consuma cuando se
planifique esa iteración.

---

## Orden de implementación

Los pasos están ordenados para que cada uno sea ejecutable y testeable de forma independiente.

1. Agregar `findByName(string $name): ?Role` a `RoleRepositoryInterface` y su implementación
   en `RoleRepositoryEloquent`. Verificar que `Role::where('name', 'client-owner')->first()`
   funciona en tinker.

2. Agregar `findByTaxId`, `isLinkedToVet` y `attachToVet` a `ClientRepositoryInterface` y
   sus implementaciones en `ClientRepositoryEloquent`. Verificar con tinker que
   `ClientRepositoryEloquent::findByTaxId('20-12345678-9')` retorna el modelo correcto.

3. Agregar `listOwnersForClient` y `findForUserAndClient` a `UserProfileRepositoryInterface`
   y sus implementaciones en `UserProfileRepositoryEloquent`. Verificar con tinker que la
   query genera el SQL correcto (usar `->toSql()`).

4. Agregar los 2 permisos nuevos en `PermissionSeeder.php` y correr:
   ```
   php artisan db:seed --class=PermissionSeeder
   php artisan db:seed --class=RoleSeeder
   ```
   Verificar en DB que `clients.owners.read` y `clients.owners.create` existen en `permissions`.

5. Crear el Mailable `back/app/Mail/ClientOwnerInvitationEmail.php`.

6. Crear la vista `back/resources/views/emails/client-owner-invitation.blade.php`.
   Copiar la estructura de `verify-account.blade.php` y adaptar el contenido.

7. Verificar que `config('app.frontend_url')` está disponible. Si no, agregar a
   `config/app.php`:
   ```php
   'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
   ```
   Y agregar `FRONTEND_URL=http://localhost:5173` en `.env` y `.env.example`.

8. Crear el Job `back/app/Jobs/SendClientOwnerInvitationJob.php`.
   Verificar que el job implementa `ShouldQueue` y que la config de queue en `.env` es
   correcta (al menos `QUEUE_CONNECTION=sync` para tests locales).

9. Agregar `findByGuid` y los 3 métodos nuevos (`lookupByTaxId`, `linkToVet`) a
   `ClientService`. No olvidar también `findByGuid(string $guid): ?Client` que llama al
   repositorio sin scope de vet.

10. Agregar `listOwnersForClient` y `addOwnerToClient` a `UserProfileService`. Verificar que
    el constructor ya tiene `UserRepositoryInterface` y `RoleRepositoryInterface` inyectados
    (lo tiene, confirmado en código).

11. Crear `back/app/Http/Requests/Clients/StoreLinkRequest.php`.

12. Crear `back/app/Http/Requests/Clients/StoreOwnerRequest.php`.

13. Agregar los métodos `lookup` y `link` a `ClientController`. Agregar el import de
    `StoreLinkRequest`.

14. Crear `back/app/Http/Controllers/V1/ClientOwnerController.php`.

15. Modificar `back/routes/api/clients.php`: agregar las 4 rutas nuevas. Verificar orden:
    `/lookup` debe ir ANTES de `/{guid}`. Agregar el import de `ClientOwnerController`.

16. Ejecutar `php artisan route:list | grep clients` y verificar que aparecen las 4 rutas
    nuevas sin colisiones.

17. Ejecutar `php artisan route:list | grep lookup` específicamente para confirmar que no
    hay ambigüedad con `/{guid}`.

18. Probar manualmente con un cliente API (Postman/Insomnia) los 4 endpoints en orden:
    a. `GET /lookup?tax_id=...` — caso found=false, luego found=true.
    b. `POST /{guid}/link` — caso happy path, luego caso ya vinculado (422).
    c. `GET /{guid}/owners` — lista vacía, luego lista con owners.
    d. `POST /{guid}/owners` — User nuevo (verificar en DB y que el job se dispara),
       luego User existente (verificar que NO se crea User nuevo), luego duplicado (422).

---

## Riesgos y consideraciones

### Riesgo 1 — Colisión de ruta /lookup con /{guid} (CRÍTICO, debe verificarse)

Si la ruta `GET /clients/lookup` no está registrada ANTES de `GET /clients/{guid}`, Laravel
interpretará "lookup" como un guid y llamará a `ClientController@show`. El resultado sería un
404 silencioso ("Cliente no encontrado") en lugar del comportamiento esperado. Verificar con
`php artisan route:list` en el paso 16.

### Riesgo 2 — tax_id duplicado en lookup retorna solo el primero

La tabla `clients` no tiene UNIQUE en `tax_id` (decisión DEC-05 del plan anterior). Si dos
clients tienen el mismo `tax_id` (ej: error de carga), `findByTaxId` retorna el más reciente.
El frontend puede mostrar el cliente incorrecto. Solución a largo plazo: agregar UNIQUE(country_id,
tax_id) en `clients` en una iteración futura, previa limpieza de duplicados.

### Riesgo 3 — addOwnerToClient: el User existente NO recibe re-invitación (supuesto implícito)

Si el email ya tiene un User registrado pero ese User nunca activó su cuenta, el owner se crea
pero no se envía el job de invitación. El nuevo owner no recibirá el email. Esto es una
decisión de negocio implícita: el sistema no sabe si el User ya activó su cuenta o no. Para
ser más robusto, se podría verificar `$user->email_verified_at === null` y si es null,
reenviar la invitación. Esto queda como deuda técnica documentada.

### Riesgo 4 — El rol 'client-owner' debe existir en DB antes de correr POST /owners

El método `addOwnerToClient` busca el rol `client-owner` via `RoleRepositoryInterface::findByName`.
Si el rol no existe (ej: el seeder no corrió o la DB está en un estado inconsistente), lanza
`RuntimeException('El rol client-owner no existe en el sistema.')` que se convierte en 422.
El dev debe verificar que el seeder de roles incluye `client-owner` antes de probar. Verificar
con: `php artisan tinker --execute="dump(App\Models\Role::where('name', 'client-owner')->first())"`.

### Riesgo 5 — Queue no configurada en desarrollo

`SendClientOwnerInvitationJob` usa `dispatch()` que encola el job. Si `QUEUE_CONNECTION=sync`
en `.env`, el job se ejecuta en el mismo proceso HTTP (útil para testing local). Si es
`database` o `redis`, necesita `php artisan queue:work` corriendo. El dev debe verificar la
configuración antes de probar el endpoint de creación de owners.

### Riesgo 6 — `config('app.frontend_url')` puede no existir

Si el archivo `config/app.php` no tiene la clave `frontend_url`, el Mailable generará URLs
con base vacía o erróneas. El paso 7 del orden de implementación lo cubre, pero si se omite,
el bug se manifiesta silenciosamente en el email enviado (link roto).

### Riesgo 7 — Multi-tenant: GET /lookup retorna datos de clientes de otros tenants

Por diseño (DEC-02), `lookup` es una búsqueda global. El endpoint está bajo `auth:sanctum` +
`vet.tenant` pero la query no filtra por vet. Esto es correcto funcionalmente pero implica
que un usuario de la Vet A puede ver el nombre y tax_id de un cliente que solo existe en la
Vet B. Esta es información mínima (no datos sanitarios ni financieros), pero debe ser
consciente y documentada. Si en el futuro se requiere mayor aislamiento, se puede agregar un
flag en `clients` para marcar visibilidad pública/privada.

### Riesgo 8 — findByName en RoleRepository: sensibilidad a mayúsculas

`findByName('client-owner')` usa `where('name', $name)`. MySQL con collation `utf8mb4_unicode_ci`
es case-insensitive, pero si la DB usa otra collation, podría no encontrar 'client-owner' si
está guardado como 'Client-Owner'. Verificar que todos los roles fueron sembrados con minúsculas
exactas por el RoleSeeder.

---

## Pendientes / fuera de alcance

1. **Re-invitación a Users existentes sin activar** — Si el email ingresado en POST /owners
   corresponde a un User con `email_verified_at = null`, no se reenvía la invitación. Requiere
   decisión de negocio sobre el comportamiento esperado.

2. **Permisos de roles tenant para owners** — Los roles `vet`, `vet-assistant`, `client-owner`
   no reciben `clients.owners.read` ni `clients.owners.create` en esta iteración. Debe definirse
   la granularidad de permisos intra-tenant con el negocio.

3. **Endpoint DELETE /owners/{ownerGuid}** — No está en el scope del brief. Quitar a un owner
   de un client requiere solo `destroy(UserProfile)` que ya existe en el repositorio, pero
   necesita el controller action y la ruta.

4. **UNIQUE(country_id, tax_id) en clients** — Recomendado para evitar el riesgo 2 del
   lookup. Requiere migration + limpieza previa de duplicados si los hay.

5. **Frontend** — Stores, composables y vistas para lookup, link y owners. Plan separado.

6. **Pantalla de onboarding por invitación** — El email de invitación apunta a
   `{frontend_url}/invitacion?token=...`. Esa ruta del frontend no existe aún. El backend
   necesita además un endpoint público (sin auth) que valide el token de invitación y permita
   setear el password. Esto está en el módulo de auth, fuera del scope de clients.
