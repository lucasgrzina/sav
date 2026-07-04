# Plan técnico: Alta de usuarios de vet desde panel tenant

## Input procesado

Brief informal del usuario (texto libre en el chat). Feature de alta de usuarios de veterinaria desde el panel tenant (`/vets/:vetSlug/usuarios/crear`), reemplazando el modal `CreateTenantUserModal` por una página separada con flujo de lookup por email en 3 estados.

---

## Unificación de terminología

El proyecto ya tiene código de "staff" en el panel admin (rutas `/v1/admin/vets/{guid}/staff`, requests `AdminAssignStaffRequest`, `AdminChangeStaffRoleRequest`, composables `useAdminVetStaff`, `useAdminAssignStaff`, etc.). El plan original introdujo inconsistencias al usar "member" y "user" para el panel tenant. Este documento unifica toda la terminología bajo "staff".

### Tabla de renombres aplicada

| Antes | Después |
|-------|---------|
| `MemberController` | `VetStaffController` |
| `AssignMemberRequest` | `AssignVetStaffRequest` |
| `ChangeRoleMemberRequest` | `ChangeVetStaffRoleRequest` |
| `LookupMemberRequest` | `LookupVetStaffRequest` |
| `CreateVetMemberRequest` | `CreateVetStaffRequest` |
| Ruta `/v1/vets/{vet}/members` | `/v1/vets/{vet}/staff` |
| `vet-user.types.ts` (archivo nuevo) | Eliminado — se extiende `vet.types.ts` existente |
| `vet-users.api.ts` (archivo nuevo) | Funciones tenant agregadas al `vet-staff.api.ts` existente |
| `useCreateVetUser.ts` | `useCreateVetStaff.ts` |
| `useAssignVetMember.ts` | `useAssignVetStaff.ts` |
| `useLookupVetUser.ts` | `useLookupVetStaff.ts` |
| `vet-user.validator.ts` | `vet-staff.validator.ts` |
| `VetUserNewForm.vue` | `VetStaffNewForm.vue` |
| `VetUserAssignForm.vue` | `VetStaffAssignForm.vue` |
| `VetUserLookupForm.vue` | `VetStaffLookupForm.vue` |
| `VetUserCreatePage.vue` | `VetStaffCreatePage.vue` |
| `VetMemberCreatePayload` | `VetStaffCreatePayload` |
| `VetMemberAssignPayload` | `VetStaffAssignPayload` |
| `VetMemberLookupResult` | `VetStaffLookupResult` |
| Constante `VET_ROLES` en `UserProfileService` (propuesta) | `VET_STAFF_ROLES` movida desde `AdminAssignStaffRequest` a `UserProfileService` |
| `useCreateVetUser` / `useAssignVetMember` / `useLookupVetUser` en query keys | `['vet-staff-lookup', ...]` / `['staff', vetSlug]` |

### Constante `VET_STAFF_ROLES`: consolidación

`AdminAssignStaffRequest` define actualmente:
```php
public const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'];
```

El plan original proponía agregar `VET_ROLES` en `UserProfileService` con los mismos valores (duplicado). La solución correcta:

1. Agregar en `UserProfileService`: `public const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'];`
2. En `AdminAssignStaffRequest`: reemplazar `self::VET_STAFF_ROLES` por `UserProfileService::VET_STAFF_ROLES` y eliminar la constante local.
3. Los nuevos requests `AssignVetStaffRequest` y `CreateVetStaffRequest` usan `UserProfileService::VET_STAFF_ROLES`.

### Convención de nombres para composables

Los composables admin existentes tienen prefijo `useAdmin`:
- `useAdminVetStaff`, `useAdminAssignStaff`, `useAdminChangeStaffRole`, `useAdminRemoveStaff`

Los nuevos composables tenant NO llevan prefijo `Admin`:
- `useLookupVetStaff`, `useCreateVetStaff`, `useAssignVetStaff`

---

## Resumen ejecutivo

Se implementa un flujo de incorporación de personal de vet desde el panel tenant con lookup por email, análogo al patrón `ClientLookupForm` + `ClientCreatePage` ya existente. El flujo detecta si el email pertenece a un usuario ya existente en el sistema (vinculable vs. ya vinculado a esta vet) o si es nuevo (requiere crear usuario + perfil + invitación). En backend se renombra `MemberController` a `VetStaffController`, las rutas `members` pasan a `staff`, y se agregan dos endpoints nuevos (`GET /lookup` y `POST /new-user`), un Job `SendVetStaffInvitationJob` con su Mailable, y se amplía `UserProfileService` con los métodos `lookupForVet` y `createAndAssignVetStaff`. En frontend se crea la página `VetStaffCreatePage`, el orquestador `VetStaffLookupForm`, dos formularios (`VetStaffNewForm` y `VetStaffAssignForm`), tres composables tenant, tipos nuevos en `vet.types.ts` y funciones tenant en `vet-staff.api.ts`. La ruta nueva se agrega en `vets-tenant.routes.ts` y `VetUsersPage` cambia el botón de abrir modal por navegación.

---

## Decisiones tomadas

**DEC-01 — Ruta del endpoint de lookup: `GET staff/lookup` antes de `{guid}`**
  Decisión: La ruta `GET /v1/vets/{vet}/staff/lookup` se registra explícitamente ANTES de `DELETE /{guid}` y `PATCH /{guid}/role` en `vets.php`. Laravel resuelve las rutas en orden de registro; si `lookup` estuviera después de `/{guid}`, el string `"lookup"` sería interpretado como un guid y causaría un 404 o un error de validación.
  Justificación: El mismo problema existe en `clients.php` donde `clients/lookup` se registra antes de `{guid}`. El patrón ya existe y está probado.
  Alternativa descartada: Usar `staff/email-search` o similar — descartado, `lookup` es el verbo ya establecido en el proyecto para este patrón.

**DEC-02 — Endpoint para crear usuario nuevo: `POST staff/new-user`**
  Decisión: Se usa la ruta `POST /v1/vets/{vet}/staff/new-user` (también antes de `{guid}`) para diferenciar semánticamente de `POST /v1/vets/{vet}/staff` (que asigna un usuario existente). No se sobrecarga el `POST /staff` con lógica condicional.
  Justificación: El `POST /staff` ya existe y tiene un contrato validado (`user_guid` + `role_guid`). Sobrecargar implicaría romper el request existente o agregar condicionales de negocio en el request de validación. Dos endpoints con responsabilidades claras es preferible.
  Alternativa descartada: Un único `POST /staff` con flag `create_if_not_exists` — descartado, viola el principio de responsabilidad única y complica los tests.

**DEC-03 — Contactos en `POST /staff` (flujo found-linkable): SÍ se soportan**
  Decisión: Se amplía `AssignVetStaffRequest` para aceptar `contacts[]` opcionales. En `VetStaffController::store()`, después de `addMember()`, si hay contactos en el request se crean usando `ContactService::create()` sobre el `$profile` resultante.
  Justificación: El usuario ya existe pero no tiene contactos en esta vet. La UI lo permite y es consistente con `createAndAssignVetStaff`. Se reutiliza `ContactService::create()` que ya maneja la lógica de `is_primary` + `clearPrimaryForType`.
  Alternativa descartada: Solo permitir contactos al crear usuario nuevo — descartado, inconsistente con el brief que confirma contactos en ambos flujos.

**DEC-04 — Constante de roles válidos: `VET_STAFF_ROLES` en `UserProfileService`**
  Decisión: Se mueve la constante `VET_STAFF_ROLES` de `AdminAssignStaffRequest` a `UserProfileService` como fuente única de verdad. `AdminAssignStaffRequest` pasa a referenciar `UserProfileService::VET_STAFF_ROLES`. Los nuevos requests `AssignVetStaffRequest` y `CreateVetStaffRequest` también la referencian. El `TENANT_ROLES` existente no se toca.
  Justificación: Los roles `client-*` no deben aparecer en el flujo de alta de personal de vet. Centralizar la constante en el service (que ya es el lugar de la lógica de negocio) evita duplicación y garantiza que si se agrega `vet-receptionist` baste modificar un solo lugar. `AdminAssignStaffRequest` ya no es "propietario" de la constante sino que la consume.
  Alternativa descartada: Mantener la constante en `AdminAssignStaffRequest` y duplicarla en los nuevos requests — descartado por duplicación. Filtrar `TENANT_ROLES` con `startsWith('vet')` — descartado, demasiado implícito y frágil.

**DEC-05 — Contactos en el batch de creación: usar `ContactService::create()` directamente**
  Decisión: En `createAndAssignVetStaff()`, los contactos se crean iterando el array con `$this->contactService->create($profile, $contactData)`. El `ContactService` ya maneja la lógica de `clearPrimaryForType` y normalización. No se usa `$profile->contacts()->createMany()` directo.
  Justificación: `ContactService::create()` tiene la lógica de `clearPrimaryForType` que garantiza que no haya más de un contacto principal por tipo. Si se usa `createMany` directamente, se omite esa lógica y podrían quedar múltiples contactos marcados como `is_primary` para el mismo tipo.
  Implicación: `UserProfileService` inyecta también `ContactService` (ver Archivos a modificar — Paso 7).

**DEC-06 — `lookupForVet` retorna un array PHP, no un Resource**
  Decisión: El método `lookupForVet(string $email, Vet $vet): array` retorna `['found' => bool, 'already_linked' => bool|null, 'user' => ['guid', 'first_name', 'last_name', 'email'] | null]`. El controller serializa esto directamente en `makeSuccess()`. No se crea un Resource separado para el lookup.
  Justificación: El payload de lookup es un DTO simple de 3-4 campos con lógica de negocio trivial. Crear un Resource completo para esto es over-engineering. El patrón existente del proyecto en `ClientController::lookup()` también retorna el array directamente.
  Alternativa descartada: `StaffLookupResource` — descartado por innecesario dado el patrón establecido.

**DEC-07 — Email de invitación: vista Blade de texto plano, igual que `client-owner-invitation`**
  Decisión: `VetStaffInvitationMail` usa una vista Blade de texto plano `emails.vet-staff-invitation`. El contenido comunica que el usuario fue invitado como personal de `{vetName}` en `{app.name}` y le da el link de activación.
  Justificación: `ClientOwnerInvitationEmail` ya usa texto plano y funciona. Mantener consistencia. El sistema no tiene sistema de templates HTML para emails actualmente.
  Alternativa descartada: HTML template — fuera de scope, requeriría componentes de email no existentes.

**DEC-08 — Ruta frontend: `usuarios/crear` (español, consistente con las rutas tenant existentes)**
  Decisión: La ruta nueva es `path: 'usuarios/crear'` con `name: 'vet-tenant-usuarios-crear'`. El nombre del componente página cambia a `VetStaffCreatePage.vue` pero el path de la URL no cambia.
  Justificación: La ruta existente de la lista es `path: 'usuarios'` (en español). El patrón de la vet tenant usa rutas en español. Usar `users/create` mezclaría idiomas.
  Alternativa descartada: `users/create` en inglés — inconsistente con las rutas existentes del panel tenant.

**DEC-09 — `ContactsInput` como componente compartido en `front/src/components/forms/`**
  Decisión: El componente va en `front/src/components/forms/ContactsInput.vue` (nivel global, no en un módulo específico). Modifica `ClientForm.vue` para usarlo.
  Justificación: `ContactFormItem` ya está en `@/modules/vets/types/vet.types.ts` (usado por `ClientForm`). El componente nuevo también lo usará. Poner el componente en `components/forms/` (nivel global) es coherente con otros componentes compartidos del proyecto y no crea acoplamiento cruzado entre módulos.
  Alternativa descartada: En `modules/users/components/forms/` — descartado porque `ClientForm` también lo necesita, y los módulos no deben importarse entre sí.

**DEC-10 — `use_for_alerts` en `ContactsInput`: incluido pero sin checkbox explícito en el UI inicial**
  Decisión: `ContactFormItem` tiene `use_for_alerts`. El componente `ContactsInput` lo incluye en el modelo pero no renderiza un checkbox para él en esta iteración (valor por defecto `false`). Esto es consistente con `ClientForm.vue` que tampoco tiene checkbox para `use_for_alerts`.
  Justificación: El campo existe en el modelo para soporte futuro de alertas. La UI de `ClientForm` ya establece el precedente de no mostrarlo en el formulario de creación. Mantener consistencia.

**DEC-11 — `vetSlug` en los composables: obtenido de `useRoute()`, NO del `useVetStore`**
  Decisión: Los composables `useLookupVetStaff`, `useCreateVetStaff` y `useAssignVetStaff` obtienen el `vetSlug` con `computed(() => route.params.vetSlug as string)` directamente de `useRoute()`.
  Justificación: Es el mismo patrón que `useLookupClient`, `useCreateClient` y otros composables del módulo `clients`. El `useVetStore` requiere que el guard haya hidratado la vet completa, pero `vetSlug` siempre está disponible en la URL. Usar `useRoute()` es más simple y directo.
  Alternativa descartada: `useVetStore().vetSlug` — requeriría que el store esté hidratado; el guard lo garantiza pero usar la ruta directamente es más explícito y no tiene dependencia de estado.

**DEC-12 — Tipos nuevos: extender `vet.types.ts`, no crear archivo separado**
  Decisión: Los tipos `VetStaffLookupResult`, `VetStaffCreatePayload` y `VetStaffAssignPayload` se agregan al final de `front/src/modules/vets/types/vet.types.ts`. El archivo no se crea como `vet-user.types.ts` separado.
  Justificación: `vet.types.ts` ya contiene `VetStaffItem`, `VetStaffUserItem`, `VetStaffRoleItem`, `AssignStaffPayload` y `VET_STAFF_ROLES`. Los nuevos tipos son extensiones naturales de ese dominio. Crear un archivo separado fragmentaría el dominio sin beneficio.
  Alternativa descartada: `vet-staff-tenant.types.ts` — descartado; el archivo solo tendría 3 interfaces y crearía un split artificial.

**DEC-13 — Funciones API tenant: agregar a `vet-staff.api.ts` existente**
  Decisión: Las funciones `lookupVetStaffApi`, `createVetStaffApi` y `assignVetStaffApi` se agregan al final de `front/src/modules/vets/api/vet-staff.api.ts` bajo un comentario separador. Las funciones admin existentes no se tocan.
  Justificación: Evita crear un archivo `vet-users.api.ts` innecesario. El archivo ya tiene el patrón de funciones para el mismo dominio (staff de vet). La separación por comentario es suficiente para distinguir panel admin de panel tenant.
  Alternativa descartada: `vet-users.api.ts` nuevo — descartado por fragmentación innecesaria.

**DEC-14 — `VetUsersPage.vue`: no renombrar**
  Decisión: El componente `VetUsersPage.vue` (lista de usuarios del tenant) mantiene su nombre actual. Solo se modifica su botón "Nuevo usuario" para navegar a la página de creación en lugar de abrir el modal.
  Justificación: El archivo ya existe en producción. Renombrarlo a `VetStaffPage.vue` requeriría actualizar el router y potencialmente otros imports, sin beneficio funcional para este feature. El impacto del cambio no justifica el riesgo de regresión.
  Alternativa descartada: Renombrar a `VetStaffPage.vue` — descartado para minimizar el alcance del cambio.

---

## Cambios en BACKEND

### Paso 0 — Renombres de archivos y rutas existentes

Antes de crear cualquier archivo nuevo, el dev debe renombrar los artefactos existentes del panel tenant:

| Archivo actual | Archivo nuevo |
|----------------|---------------|
| `back/app/Http/Controllers/V1/MemberController.php` | `back/app/Http/Controllers/V1/VetStaffController.php` |
| `back/app/Http/Requests/Members/AssignMemberRequest.php` | `back/app/Http/Requests/Members/AssignVetStaffRequest.php` |
| `back/app/Http/Requests/Members/ChangeRoleMemberRequest.php` | `back/app/Http/Requests/Members/ChangeVetStaffRoleRequest.php` |

Cambios en rutas (`back/routes/api/vets.php`):
- `Route::prefix('members')` → `Route::prefix('staff')`
- Todas las referencias a `MemberController::class` → `VetStaffController::class`

Cambios internos en los archivos renombrados:
- `class MemberController` → `class VetStaffController`
- `class AssignMemberRequest` → `class AssignVetStaffRequest`; reemplazar `UserProfileService::TENANT_ROLES` por `UserProfileService::VET_STAFF_ROLES` (el rol válido para personal de vet es más restrictivo que `TENANT_ROLES`)
- `class ChangeRoleMemberRequest` → `class ChangeVetStaffRoleRequest`

**Nota sobre `AssignVetStaffRequest`:** La request original usaba `UserProfileService::TENANT_ROLES` (que incluye roles de client). En el contexto del panel tenant de vet, el selector de roles debe restricirse a `VET_STAFF_ROLES`. Verificar si el comportamiento actual intencionalmente permite asignar roles `client-*` a través de este endpoint. Si es así, mantener `TENANT_ROLES` en `AssignVetStaffRequest` y documentarlo. Si es un bug, corregir a `VET_STAFF_ROLES`.

---

### Archivos a crear

#### `back/app/Http/Requests/Members/LookupVetStaffRequest.php`

**Propósito:** Valida el query param `email` del endpoint `GET /v1/vets/{vet}/staff/lookup`.

```php
namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;

class LookupVetStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El email es obligatorio.',
            'email.email'    => 'El email no tiene un formato válido.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/CreateVetStaffRequest.php`

**Propósito:** Valida el body del endpoint `POST /v1/vets/{vet}/staff/new-user` (crea usuario nuevo + lo asigna a la vet).

```php
namespace App\Http\Requests\Members;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVetStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name'  => ['required', 'string', 'max:50'],
            'email'      => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role_guid'  => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::VET_STAFF_ROLES);
                }),
            ],
            'contacts'                  => ['nullable', 'array'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'          => ['required', 'string', 'max:200'],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required'  => 'El apellido es obligatorio.',
            'email.required'      => 'El email es obligatorio.',
            'email.email'         => 'El email no tiene un formato válido.',
            'email.unique'        => 'Este email ya está registrado en el sistema. Usá el flujo de búsqueda.',
            'role_guid.required'  => 'El rol es obligatorio.',
            'role_guid.exists'    => 'El rol seleccionado no es válido para el personal de una veterinaria.',
        ];
    }
}
```

**Nota:** La regla `unique:users,email` es la segunda línea de defensa. El frontend ya verificó con `lookup` que el email no existe, pero puede haber una race condition si otro proceso crea el mismo usuario entre el lookup y el submit.

---

#### `back/app/Jobs/SendVetStaffInvitationJob.php`

**Propósito:** Job encolable que envía el email de invitación al personal de vet recién creado. Réplica exacta de la estructura de `SendClientOwnerInvitationJob`.

```php
namespace App\Jobs;

use App\Mail\VetStaffInvitationMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendVetStaffInvitationJob implements ShouldQueue
{
    use QueueableTrait, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int    $userId,
        public readonly string $vetName,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            Log::warning('SendVetStaffInvitationJob: usuario no encontrado', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // Regenerar token si expiró
        if (!$user->verification_link_token || now()->isAfter($user->verification_link_expires_at)) {
            $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);
            $user->verification_link_token      = Str::random(64);
            $user->verification_link_expires_at = now()->addHours($expirationHours);
            $user->save();
        }

        try {
            Mail::to($user->email)->send(
                new VetStaffInvitationMail($user, $this->vetName)
            );
        } catch (\Exception $e) {
            Log::error('SendVetStaffInvitationJob: fallo al enviar email', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendVetStaffInvitationJob falló definitivamente', [
            'user_id' => $this->userId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
```

---

#### `back/app/Mail/VetStaffInvitationMail.php`

**Propósito:** Mailable que genera el email de invitación para personal de vet.

```php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VetStaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $vetName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitación al equipo de ' . $this->vetName . ' en ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $frontendUrl     = rtrim(config('app.frontend_url'), '/');
        $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);
        $invitationUrl   = $frontendUrl
            . '/invitacion'
            . '?token=' . urlencode($this->user->verification_link_token)
            . '&email=' . urlencode($this->user->email);

        return new Content(
            view: 'emails.vet-staff-invitation',
            with: [
                'firstName'       => $this->user->first_name,
                'vetName'         => $this->vetName,
                'invitationUrl'   => $invitationUrl,
                'expirationHours' => $expirationHours,
            ],
        );
    }
}
```

---

#### `back/resources/views/emails/vet-staff-invitation.blade.php`

**Propósito:** Vista de texto plano para el email de invitación de personal de vet.

```
Hola {{ $firstName }},

Fuiste invitado/a a formar parte del equipo de {{ $vetName }} en {{ config('app.name') }}.

Para activar tu cuenta y establecer tu contraseña, hacé clic en el siguiente enlace:

{{ $invitationUrl }}

Este enlace expira en {{ $expirationHours }} horas.

Si no esperabas esta invitación, podés ignorar este mensaje.

Saludos,
El equipo de {{ config('app.name') }}
```

---

### Archivos a modificar

#### `back/app/Services/UserProfileService.php`

**Cambio 1:** Mover la constante `VET_STAFF_ROLES` desde `AdminAssignStaffRequest` a esta clase como fuente única de verdad. Reemplaza la propuesta original de `VET_ROLES` (nombre incorrecto).

**Antes:**
```php
public const TENANT_ROLES = [
    'vet', 'vet-assistant', 'vet-administrative',
    'client-owner', 'client-manager', 'client-administrative',
];
```

**Después:**
```php
public const TENANT_ROLES = [
    'vet', 'vet-assistant', 'vet-administrative',
    'client-owner', 'client-manager', 'client-administrative',
];

/** Roles válidos para personal de veterinaria (subset de TENANT_ROLES). */
public const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'];
```

**Cambio 2:** Agregar inyección de `ContactService` en el constructor.

**Antes:**
```php
public function __construct(
    private UserProfileRepositoryInterface $userProfileRepository,
    private UserRepositoryInterface        $userRepository,
    private RoleRepositoryInterface        $roleRepository,
) {}
```

**Después:**
```php
use App\Services\ContactService;

public function __construct(
    private UserProfileRepositoryInterface $userProfileRepository,
    private UserRepositoryInterface        $userRepository,
    private RoleRepositoryInterface        $roleRepository,
    private ContactService                 $contactService,
) {}
```

**Cambio 3:** Agregar método `lookupForVet`.

```php
/**
 * Busca un User por email y verifica si ya es miembro de la vet dada.
 *
 * Retorna:
 *   ['found' => false]                                    — email no existe en el sistema
 *   ['found' => true, 'already_linked' => false, 'user' => [...]]  — existe, no está en esta vet
 *   ['found' => true, 'already_linked' => true,  'user' => [...]]  — existe y ya está en esta vet
 */
public function lookupForVet(string $email, Vet $vet): array
{
    $user = $this->userRepository->findByEmail($email);

    if (!$user) {
        return ['found' => false, 'already_linked' => null, 'user' => null];
    }

    $existing = $this->userProfileRepository->findForUserAndVet($user, $vet);

    return [
        'found'          => true,
        'already_linked' => $existing !== null,
        'user'           => [
            'guid'       => $user->guid,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->email,
        ],
    ];
}
```

**Cambio 4:** Agregar método `createAndAssignVetStaff` (renombrado desde el plan original `createAndAssignVetMember`).

```php
/**
 * Crea un User nuevo (con invitación) y lo asigna como personal de la Vet.
 *
 * Flujo (dentro de DB::transaction):
 *   1. Crear User con password temporal + token de verificación.
 *   2. Resolver el rol por guid (validado en CreateVetStaffRequest).
 *   3. Crear UserProfile(authenticatable_type='vet', authenticatable_id=$vet->id).
 *   4. Crear contactos opcionales usando ContactService (maneja is_primary).
 *   5. Encolar SendVetStaffInvitationJob.
 *
 * @param Vet   $vet   Tenant al que se agrega el personal
 * @param array $data  Datos validados de CreateVetStaffRequest
 */
public function createAndAssignVetStaff(Vet $vet, array $data): UserProfile
{
    return DB::transaction(function () use ($vet, $data) {
        $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);

        $user = $this->userRepository->create([
            'first_name'                   => $data['first_name'],
            'last_name'                    => $data['last_name'],
            'name'                         => $data['first_name'] . ' ' . $data['last_name'],
            'email'                        => $data['email'],
            'password'                     => Hash::make(Str::random(32)),
            'email_verified_at'            => null,
            'verification_link_token'      => Str::random(64),
            'verification_link_expires_at' => now()->addHours($expirationHours),
        ]);

        $role = $this->roleRepository->findByGuid($data['role_guid']);
        if (!$role) {
            throw new \RuntimeException('El rol especificado no existe.');
        }

        $profile = $this->userProfileRepository->create([
            'user_id'              => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $vet->id,
            'role_id'              => $role->id,
        ]);

        foreach ($data['contacts'] ?? [] as $contactData) {
            $this->contactService->create($profile, $contactData);
        }

        SendVetStaffInvitationJob::dispatch($user->id, $vet->name);

        return $profile;
    });
}
```

**Nota sobre imports a agregar al tope del archivo:**
```php
use App\Jobs\SendVetStaffInvitationJob;
// Hash y Str ya están importados (se usan en addOwnerToClient)
```

---

#### `back/app/Http/Controllers/V1/VetStaffController.php` (renombrado desde `MemberController`)

**Cambio 1:** Renombrar la clase y actualizar imports.

```php
// Antes: class MemberController
// Después:
class VetStaffController extends Controller
{
    use ApiResponseTrait;
}
```

**Cambio 2:** Agregar imports nuevos.

```php
use App\Http\Requests\Members\LookupVetStaffRequest;
use App\Http\Requests\Members\CreateVetStaffRequest;
use App\Services\ContactService;
```

**Cambio 3:** Inyectar `ContactService` en el constructor.

```php
public function __construct(
    private UserProfileService $userProfileService,
    private ContactService     $contactService,
) {}
```

**Cambio 4:** Agregar método `lookup`.

```php
public function lookup(LookupVetStaffRequest $request): JsonResponse
{
    try {
        $vet    = $request->attributes->get('current_vet');
        $result = $this->userProfileService->lookupForVet(
            $request->validated()['email'],
            $vet
        );

        return $this->makeSuccess($result);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Cambio 5:** Agregar método `createAndAssign`.

```php
public function createAndAssign(CreateVetStaffRequest $request): JsonResponse
{
    try {
        $vet     = $request->attributes->get('current_vet');
        $profile = $this->userProfileService->createAndAssignVetStaff(
            $vet,
            $request->validated()
        );

        return $this->makeSuccess(
            new UserProfileResource($profile->load(['user', 'role', 'contacts'])),
            'Usuario creado e incorporado al equipo correctamente.',
            201
        );
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Cambio 6:** Modificar el método `store()` existente para soportar contactos opcionales y cargar relaciones.

**Antes (fragmento relevante):**
```php
$profile = $this->userProfileService->addMember($vet, $user, $role);

return $this->makeSuccess(new UserProfileResource($profile), 'Miembro agregado correctamente.', 201);
```

**Después:**
```php
$profile = $this->userProfileService->addMember($vet, $user, $role);

foreach ($data['contacts'] ?? [] as $contactData) {
    $this->contactService->create($profile, $contactData);
}

return $this->makeSuccess(
    new UserProfileResource($profile->load(['user', 'role', 'contacts'])),
    'Personal incorporado al equipo correctamente.',
    201
);
```

---

#### `back/app/Http/Requests/Members/AdminAssignStaffRequest.php`

**Cambio:** Reemplazar la constante local `VET_STAFF_ROLES` por referencia a `UserProfileService::VET_STAFF_ROLES`.

**Antes:**
```php
class AdminAssignStaffRequest extends FormRequest
{
    public const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'];

    public function rules(): array
    {
        return [
            // ...
            Rule::exists('roles', 'guid')->where(function ($query) {
                $query->whereIn('name', self::VET_STAFF_ROLES);
            }),
            // ...
        ];
    }
}
```

**Después:**
```php
use App\Services\UserProfileService;

class AdminAssignStaffRequest extends FormRequest
{
    // Constante eliminada — usar UserProfileService::VET_STAFF_ROLES

    public function rules(): array
    {
        return [
            // ...
            Rule::exists('roles', 'guid')->where(function ($query) {
                $query->whereIn('name', UserProfileService::VET_STAFF_ROLES);
            }),
            // ...
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/AssignVetStaffRequest.php` (renombrado desde `AssignMemberRequest`)

**Cambio 1:** Renombrar la clase.
```php
// Antes: class AssignMemberRequest
// Después: class AssignVetStaffRequest
```

**Cambio 2:** Agregar validación de `contacts[]` opcionales.

**Antes:**
```php
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
```

**Después:**
```php
public function rules(): array
{
    return [
        'user_guid' => ['required', 'string', 'exists:users,guid'],
        'role_guid' => [
            'required',
            'string',
            Rule::exists('roles', 'guid')->where(function ($query) {
                $query->whereIn('name', UserProfileService::VET_STAFF_ROLES);
            }),
        ],
        'contacts'                  => ['nullable', 'array'],
        'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
        'contacts.*.value'          => ['required', 'string', 'max:200'],
        'contacts.*.label'          => ['nullable', 'string', 'max:100'],
        'contacts.*.is_primary'     => ['nullable', 'boolean'],
        'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
    ];
}
```

**Nota:** El cambio de `TENANT_ROLES` a `VET_STAFF_ROLES` restringe los roles válidos para el panel tenant. Verificar con el equipo si es el comportamiento deseado (ver R11 en Riesgos).

---

#### `back/routes/api/vets.php`

**Cambio:** Renombrar el prefijo `members` a `staff` y actualizar referencias al controller. Registrar las dos rutas nuevas ANTES de las rutas con `{guid}`.

**Antes:**
```php
Route::prefix('members')->group(function () {
    Route::get('/', [MemberController::class, 'index']);
    Route::post('/', [MemberController::class, 'store']);
    Route::delete('/{guid}', [MemberController::class, 'destroy']);
    Route::patch('/{guid}/role', [MemberController::class, 'changeRole']);
    // ...
});
```

**Después:**
```php
Route::prefix('staff')->group(function () {
    Route::get('/', [VetStaffController::class, 'index']);
    Route::post('/', [VetStaffController::class, 'store']);
    // Rutas estáticas ANTES de las rutas con parámetros dinámicos
    Route::get('/lookup', [VetStaffController::class, 'lookup']);
    Route::post('/new-user', [VetStaffController::class, 'createAndAssign']);
    // Rutas dinámicas al final
    Route::delete('/{guid}', [VetStaffController::class, 'destroy']);
    Route::patch('/{guid}/role', [VetStaffController::class, 'changeRole']);

    // Contactos de un miembro del staff
    Route::prefix('/{profile}/contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::put('/{guid}', [ContactController::class, 'update']);
        Route::delete('/{guid}', [ContactController::class, 'destroy']);
    });
});
```

---

### Rutas API

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| `GET`  | `/v1/vets/{vet}/staff` | `VetStaffController@index` | `auth:sanctum`, `vet.tenant` (heredados) |
| `POST` | `/v1/vets/{vet}/staff` | `VetStaffController@store` | `auth:sanctum`, `vet.tenant` (heredados) |
| `GET`  | `/v1/vets/{vet}/staff/lookup` | `VetStaffController@lookup` | `auth:sanctum`, `vet.tenant` (heredados) |
| `POST` | `/v1/vets/{vet}/staff/new-user` | `VetStaffController@createAndAssign` | `auth:sanctum`, `vet.tenant` (heredados) |
| `DELETE` | `/v1/vets/{vet}/staff/{guid}` | `VetStaffController@destroy` | `auth:sanctum`, `vet.tenant` (heredados) |
| `PATCH` | `/v1/vets/{vet}/staff/{guid}/role` | `VetStaffController@changeRole` | `auth:sanctum`, `vet.tenant` (heredados) |

**Nota de seguridad multi-tenant:** Todas las rutas heredan el middleware `vet.tenant` del grupo padre, que pone el `current_vet` en `$request->attributes`. El `VetStaffController` obtiene la vet via `$request->attributes->get('current_vet')`, que garantiza que el tenant del usuario autenticado coincida con la vet de la URL. No hay riesgo de cross-tenant.

---

### Contrato de los endpoints nuevos

**`GET /v1/vets/{vet}/staff/lookup?email=juan@ejemplo.com`**

Response — email no existe:
```json
{
  "success": true,
  "data": { "found": false, "already_linked": null, "user": null }
}
```

Response — email existe, no está en la vet:
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": false,
    "user": {
      "guid": "a1b2c3d4-...",
      "first_name": "Juan",
      "last_name": "García",
      "email": "juan@ejemplo.com"
    }
  }
}
```

Response — email existe y ya es staff de esta vet:
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": true,
    "user": {
      "guid": "a1b2c3d4-...",
      "first_name": "Juan",
      "last_name": "García",
      "email": "juan@ejemplo.com"
    }
  }
}
```

Errores:

| HTTP | Situación |
|------|-----------|
| 422  | `email` falta o formato inválido |
| 401  | No autenticado |
| 403  | No pertenece a este tenant |

---

**`POST /v1/vets/{vet}/staff/new-user`**

Request:
```json
{
  "first_name": "María",
  "last_name": "López",
  "email": "maria@ejemplo.com",
  "role_guid": "guid-del-rol-vet-assistant",
  "contacts": [
    {
      "type": "email",
      "value": "maria@ejemplo.com",
      "label": "Trabajo",
      "is_primary": true,
      "use_for_alerts": false
    },
    {
      "type": "phone",
      "value": "+5491112345678",
      "label": null,
      "is_primary": true,
      "use_for_alerts": true
    }
  ]
}
```

Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "profile-guid",
    "user": {
      "guid": "user-guid",
      "name": "María López",
      "first_name": "María",
      "last_name": "López",
      "email": "maria@ejemplo.com"
    },
    "role": {
      "guid": "rol-guid",
      "name": "vet-assistant"
    },
    "contacts": [
      {
        "guid": "contact-guid",
        "type": "email",
        "value": "maria@ejemplo.com",
        "label": "Trabajo",
        "is_primary": true,
        "use_for_alerts": false,
        "created_at": "2026-06-17T..."
      }
    ],
    "created_at": "2026-06-17T..."
  },
  "message": "Usuario creado e incorporado al equipo correctamente."
}
```

Errores:

| HTTP | Código de error | Cuándo |
|------|-----------------|--------|
| 422  | —               | Validación: campos faltantes, email ya registrado, rol inválido |
| 422  | —               | `RuntimeException`: rol no encontrado (segunda defensa) |
| 500  | —               | Error de DB inesperado |

---

**`POST /v1/vets/{vet}/staff` (modificado para soportar contacts opcionales)**

Request:
```json
{
  "user_guid": "guid-del-usuario",
  "role_guid": "guid-del-rol",
  "contacts": [
    {
      "type": "phone",
      "value": "+5491187654321",
      "label": null,
      "is_primary": true,
      "use_for_alerts": false
    }
  ]
}
```

La key `contacts` es opcional. Si se omite, el comportamiento es idéntico al actual. La respuesta ahora siempre incluye `contacts` (puede ser array vacío).

---

### Tests a generar (backend, qué cubrir)

**Feature tests — `VetStaffController`:**

1. `GET staff/lookup?email=nuevo@email.com` → retorna `found: false`.
2. `GET staff/lookup?email=existente@email.com` donde user no está en esta vet → retorna `found: true, already_linked: false` con datos del user.
3. `GET staff/lookup?email=miembro@email.com` donde user YA es staff → retorna `found: true, already_linked: true`.
4. `GET staff/lookup` sin email → 422.
5. `GET staff/lookup` desde otro tenant (user autenticado no pertenece a la vet de la URL) → el middleware `vet.tenant` debe retornar 403.
6. `POST staff/new-user` con datos válidos → 201, crea user, crea profile, encola job.
7. `POST staff/new-user` con email ya existente → 422 con `email` en errors.
8. `POST staff/new-user` con role_guid de rol `client-owner` → 422.
9. `POST staff/new-user` con contacts válidos → 201, los contactos aparecen en la respuesta.
10. `POST staff/new-user` desde otro tenant → el middleware debe retornar 403.
11. `POST staff` con contacts → 201, miembro asignado y contactos creados.
12. `POST staff` sin contacts → 201, comportamiento idéntico al original.

**Unit tests — `UserProfileService`:**

1. `lookupForVet` con email no existente → `found: false`.
2. `lookupForVet` con email existente sin perfil en la vet → `found: true, already_linked: false`.
3. `lookupForVet` con email existente con perfil en la vet → `found: true, already_linked: true`.
4. `createAndAssignVetStaff` crea user, profile y contactos dentro de transacción.
5. `createAndAssignVetStaff` despacha `SendVetStaffInvitationJob`.
6. `createAndAssignVetStaff` lanza `RuntimeException` si el rol no existe (edge case de datos corruptos).

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/components/forms/ContactsInput.vue`

**Propósito:** Componente compartido que encapsula la lista de contactos editable. Extrae el bloque inline de `ClientForm.vue`.

```typescript
// Props y emits
interface Props {
  modelValue: ContactFormItem[]
}
const emit = defineEmits<{
  'update:modelValue': [value: ContactFormItem[]]
}>()
```

**Lógica interna:**
- Trabaja con una copia local reactiva `localContacts = ref([...props.modelValue])`.
- Cada cambio en `localContacts` emite `update:modelValue` con el nuevo array.
- Métodos: `addContact(type: 'email' | 'phone' | 'whatsapp')`, `removeContact(idx: number)`.
- El template replica exactamente el bloque de contactos de `ClientForm.vue`:
  - `a-tag` con color según tipo (`email` → azul, `phone`/`whatsapp` → verde).
  - `a-input` para `value` (placeholder diferente según tipo).
  - `a-input` para `label` (opcional, placeholder "Etiqueta opcional").
  - `a-checkbox` para `is_primary` (label: "Principal").
  - `a-button` peligroso "Quitar" → `removeContact(idx)`.
  - Botones "Agregar email", "Agregar teléfono", "Agregar WhatsApp" (+ `addContact('whatsapp')` — nuevo respecto a `ClientForm`).
- Importa `ContactFormItem` de `@/modules/vets/types/vet.types`.

**Nota:** El watcher en `props.modelValue` debe ser profundo y sincronizar `localContacts` si el padre resetea el formulario.

---

#### `front/src/modules/users/validators/vet-staff.validator.ts`

**Propósito:** Schemas Zod para los formularios de alta de personal de vet. Renombrado desde `vet-user.validator.ts`.

```typescript
import { z } from 'zod'

const contactItemSchema = z.object({
  type:           z.enum(['email', 'phone', 'whatsapp']),
  value:          z.string().min(1, 'El valor del contacto es requerido').max(200),
  label:          z.string().max(100).nullable().optional(),
  is_primary:     z.boolean().default(false),
  use_for_alerts: z.boolean().default(false),
})

/** Schema para el formulario de personal NUEVO (not-found) */
export const vetStaffNewSchema = z.object({
  first_name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(50, 'Máximo 50 caracteres')
    .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras y espacios'),
  last_name: z
    .string()
    .min(1, 'El apellido es requerido')
    .max(50, 'Máximo 50 caracteres')
    .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras y espacios'),
  email:     z.string().email('Email inválido'),   // pre-llenado + disabled, pero Zod lo valida
  role_guid: z.string().min(1, 'El rol es requerido'),
  contacts:  z.array(contactItemSchema).optional().default([]),
})

/** Schema para el formulario de personal EXISTENTE (found-linkable) */
export const vetStaffAssignSchema = z.object({
  role_guid: z.string().min(1, 'El rol es requerido'),
  contacts:  z.array(contactItemSchema).optional().default([]),
})

export type VetStaffNewForm    = z.infer<typeof vetStaffNewSchema>
export type VetStaffAssignForm = z.infer<typeof vetStaffAssignSchema>
```

---

#### `front/src/modules/users/composables/useLookupVetStaff.ts`

**Propósito:** Query manual para `GET /v1/vets/{vet}/staff/lookup`. Replica el patrón de `useLookupClient`. Renombrado desde `useLookupVetUser`.

```typescript
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { lookupVetStaffApi } from '@/modules/vets/api/vet-staff.api'
import type { VetStaffLookupResult } from '@/modules/vets/types/vet.types'

export function useLookupVetStaff() {
  const route   = useRoute()
  const vetSlug = computed(() => route.params.vetSlug as string)
  const email   = ref<string>('')
  const enabled = ref(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['vet-staff-lookup', vetSlug, email],
    queryFn:  () => lookupVetStaffApi(vetSlug.value, email.value),
    enabled:  computed(() => enabled.value && Boolean(email.value)),
    staleTime: 0,
    retry: false,
  })

  function search(newEmail: string): void {
    email.value   = newEmail
    enabled.value = true
  }

  function reset(): void {
    email.value   = ''
    enabled.value = false
    queryClient.removeQueries({ queryKey: ['vet-staff-lookup', vetSlug.value] })
  }

  return { ...query, email, search, reset }
}
```

---

#### `front/src/modules/users/composables/useCreateVetStaff.ts`

**Propósito:** Mutación para `POST /v1/vets/{vet}/staff/new-user`. Replica el patrón de `useCreateClient`. Renombrado desde `useCreateVetUser`.

```typescript
import { ref, computed } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { createVetStaffApi } from '@/modules/vets/api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffCreatePayload } from '@/modules/vets/types/vet.types'

export function useCreateVetStaff() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const route        = useRoute()
  const vetSlug      = computed(() => route.params.vetSlug as string)
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: VetStaffCreatePayload) => createVetStaffApi(vetSlug.value, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['staff', vetSlug.value] })
      success('Usuario creado e incorporado al equipo correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el usuario.'
      if (apiError.message) error('Error al crear el usuario')
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

#### `front/src/modules/users/composables/useAssignVetStaff.ts`

**Propósito:** Mutación para `POST /v1/vets/{vet}/staff` (asignar usuario existente). Renombrado desde `useAssignVetMember`.

```typescript
import { ref, computed } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { assignVetStaffApi } from '@/modules/vets/api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffAssignPayload } from '@/modules/vets/types/vet.types'

export function useAssignVetStaff() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const route        = useRoute()
  const vetSlug      = computed(() => route.params.vetSlug as string)
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: VetStaffAssignPayload) => assignVetStaffApi(vetSlug.value, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['staff', vetSlug.value] })
      success('Personal incorporado al equipo correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al incorporar al personal.'
      if (apiError.message) error('Error al incorporar al personal')
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

#### `front/src/modules/users/components/forms/VetStaffNewForm.vue`

**Propósito:** Formulario para el estado `not-found`. El usuario no existe y se debe crear. El email viene pre-llenado y deshabilitado. Renombrado desde `VetUserNewForm.vue`.

```typescript
// Props
interface Props {
  initialEmail: string
  loading?: boolean
  fieldErrors?: Record<string, string> | null
}

// Emits
const emit = defineEmits<{
  submit: [values: VetStaffNewForm]
  cancel: []
}>()
```

**Lógica interna:**
1. `useForm` con `toTypedSchema(vetStaffNewSchema)`.
2. Campos: `first_name`, `last_name`, `email` (pre-llenado con `props.initialEmail`, campo disabled), `role_guid`.
3. `role_guid` → `a-select` usando `VET_STAFF_ROLES` ya disponible en `vet.types.ts`:
   ```typescript
   import { VET_STAFF_ROLES, type VetStaffRoleName } from '@/modules/vets/types/vet.types'
   const { data: rolesData } = useRoles({ per_page: 100 })
   const roleOptions = computed(() =>
     (rolesData.value?.data ?? [])
       .filter(r => VET_STAFF_ROLES.includes(r.name as VetStaffRoleName))
       .map(r => ({ value: r.guid, label: r.name }))
   )
   ```
4. `localContacts = ref<ContactFormItem[]>([])` → `<ContactsInput v-model="localContacts" />`.
5. Watch sobre `props.fieldErrors` → `setErrors(fieldErrors ?? {})`.
6. Watch sobre `props.initialEmail` → `setFieldValue('email', props.initialEmail)`.
7. `onSubmit = handleSubmit(values => emit('submit', { ...values, contacts: localContacts.value }))`.

**Template estructura:**
```
FormSection "Datos del nuevo personal"
  a-row
    a-col first_name
    a-col last_name
  a-row
    a-col email (disabled)
    a-col role_guid (select)

FormSection "Contactos de acceso" (subtitle: "Emails y teléfonos de contacto.")
  <ContactsInput v-model="localContacts" />

FormFooter :loading="loading" save-label="Crear usuario e incorporar"
  + a-button "Cancelar" @click="emit('cancel')"
```

---

#### `front/src/modules/users/components/forms/VetStaffAssignForm.vue`

**Propósito:** Formulario para el estado `found-linkable`. Renombrado desde `VetUserAssignForm.vue`.

```typescript
// Props
interface Props {
  user: {
    guid: string
    first_name: string
    last_name: string
    email: string
  }
  loading?: boolean
  fieldErrors?: Record<string, string> | null
}

// Emits
const emit = defineEmits<{
  submit: [values: VetStaffAssignForm & { user_guid: string }]
  cancel: []
}>()
```

**Lógica interna:**
1. `useForm` con `toTypedSchema(vetStaffAssignSchema)`.
2. Solo campo `role_guid` bajo validación. Los datos del usuario se muestran como display.
3. El `role_guid` usa el mismo selector que `VetStaffNewForm`.
4. `localContacts = ref<ContactFormItem[]>([])` → `<ContactsInput />`.
5. `onSubmit = handleSubmit(values => emit('submit', { ...values, user_guid: props.user.guid, contacts: localContacts.value }))`.

**Template estructura:**
```
div.clf-card (misma clase visual que ClientLookupForm para consistencia)
  dl.clf-dl
    dt Nombre / dd {{ user.first_name }} {{ user.last_name }}
    dt Email   / dd {{ user.email }}

FormSection "Rol en el equipo"
  a-form-item role_guid (select)

FormSection "Contactos de acceso"
  <ContactsInput v-model="localContacts" />

div.clf-actions
  a-button type="primary" :loading="loading" html-type="submit" → "Incorporar al equipo"
  a-button @click="emit('cancel')" → "Cancelar"
```

---

#### `front/src/modules/users/components/forms/VetStaffLookupForm.vue`

**Propósito:** Orquestador de la máquina de estados del lookup. Renombrado desde `VetUserLookupForm.vue`.

**Tipo de estado:**
```typescript
type LookupState =
  | { status: 'idle' }
  | { status: 'searching' }
  | { status: 'found-linkable'; user: VetStaffLookupResult['user'] }
  | { status: 'found-linked';   user: VetStaffLookupResult['user'] }
  | { status: 'not-found';      email: string }
  | { status: 'creating' }
  | { status: 'done' }
```

**Emits:**
```typescript
const emit = defineEmits<{
  success: []
}>()
```

**Composables utilizados:**
- `useLookupVetStaff()` → `{ data, isLoading, isError, search, reset }`
- `useCreateVetStaff()` → `{ mutateAsync: createAsync, isPending: isCreating, fieldErrors, generalError: createError }`
- `useAssignVetStaff()` → `{ mutateAsync: assignAsync, isPending: isAssigning, generalError: assignError }`

**Máquina de estados (lógica del watch):**
```typescript
watch([data, isLoading, isError], ([result, loading, hasError]) => {
  if (loading) {
    state.value = { status: 'searching' }
    return
  }
  if (hasError) {
    state.value = { status: 'idle' }
    return
  }
  if (result === undefined) return

  if (!result.found) {
    state.value = { status: 'not-found', email: emailInput.value }
  } else if (result.already_linked) {
    state.value = { status: 'found-linked', user: result.user }
  } else {
    state.value = { status: 'found-linkable', user: result.user }
  }
})
```

**Handlers:**
```typescript
function handleSearch(): void {
  if (!emailInput.value.trim()) return
  state.value = { status: 'searching' }
  search(emailInput.value.trim())
}

function resetSearch(): void {
  emailInput.value = ''
  state.value = { status: 'idle' }
  reset()
}

async function handleAssign(values: VetStaffAssignForm & { user_guid: string }): Promise<void> {
  await assignAsync(values, {
    onSuccess: () => {
      state.value = { status: 'done' }
      emit('success')
    },
  })
}

async function handleCreate(values: VetStaffNewForm): Promise<void> {
  state.value = { status: 'creating' }
  await createAsync(
    {
      first_name: values.first_name,
      last_name:  values.last_name,
      email:      values.email,
      role_guid:  values.role_guid,
      contacts:   values.contacts ?? [],
    },
    {
      onSuccess: () => {
        state.value = { status: 'done' }
        emit('success')
      },
      onError: () => {
        state.value = { status: 'not-found', email: emailInput.value }
      },
    },
  )
}
```

**Template (estructura completa):**
```
div.clf-container
  <!-- Sección de búsqueda - siempre visible -->
  div.clf-search
    a-input v-model:value="emailInput" placeholder="Ingresá el email del usuario"
             type="email" @press-enter="handleSearch"
    a-button type="primary" :loading="state.status === 'searching'"
             :disabled="!emailInput.trim()" @click="handleSearch"
      SearchOutlined / Buscar

  a-alert v-if="isSearchError" type="error" message="Error al buscar. Intentá nuevamente."

  <!-- SEARCHING -->
  div.clf-spinner v-if="state.status === 'searching'"
    a-spin size="large"

  <!-- FOUND-LINKABLE -->
  template v-else-if="state.status === 'found-linkable'"
    a-alert type="info"
      message="Usuario encontrado en el sistema"
      description="Este usuario existe pero no pertenece a este equipo todavía."
    a-alert v-if="assignError" type="error" :message="assignError"
    VetStaffAssignForm
      :user="state.user"
      :loading="isAssigning"
      :field-errors="null"
      @submit="handleAssign"
      @cancel="resetSearch"

  <!-- FOUND-LINKED -->
  template v-else-if="state.status === 'found-linked'"
    a-alert type="warning"
      message="Este usuario ya forma parte de este equipo"
    div.clf-card
      dl.clf-dl
        dt Nombre / dd {{ state.user.first_name }} {{ state.user.last_name }}
        dt Email   / dd {{ state.user.email }}
    div.clf-actions
      a-button type="primary" @click="router.push(`/vets/${vetSlug}/usuarios`)"
        Ver personal
      a-button @click="resetSearch" Buscar otro

  <!-- NOT-FOUND o CREATING -->
  template v-else-if="state.status === 'not-found' || state.status === 'creating'"
    a-alert type="info"
      message="No se encontró ningún usuario con ese email"
      description="Completá los datos para crear el usuario e incorporarlo al equipo."
    a-alert v-if="createError" type="error" :message="createError"
    VetStaffNewForm
      :initial-email="state.status === 'not-found' ? state.email : emailInput"
      :loading="state.status === 'creating'"
      :field-errors="fieldErrors"
      @submit="handleCreate"
      @cancel="resetSearch"
```

**Estilos:** Copiar los estilos de `ClientLookupForm.vue` (`.clf-container`, `.clf-search`, `.clf-spinner`, `.clf-card`, `.clf-dl`, `.clf-actions`).

---

#### `front/src/modules/vets/pages/tenant/VetStaffCreatePage.vue`

**Propósito:** Página de incorporación de personal de vet. Renombrado desde `VetUserCreatePage.vue`.

```vue
<script setup lang="ts">
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import VetStaffLookupForm from '@/modules/users/components/forms/VetStaffLookupForm.vue'

const router  = useRouter()
const route   = useRoute()
const vetSlug = () => route.params.vetSlug as string

function handleSuccess(): void {
  router.push(`/vets/${vetSlug()}/usuarios`)
}
</script>

<template>
  <div>
    <div class="ccp-header">
      <button class="ccp-back" @click="router.push(`/vets/${vetSlug()}/usuarios`)">
        <ArrowLeftOutlined /> Volver a usuarios
      </button>
    </div>

    <PageHeader
      title="Incorporar personal al equipo"
      subtitle="Buscá el usuario por email para incorporarlo, o completá los datos para crear uno nuevo."
    />

    <VetStaffLookupForm @success="handleSuccess" />
  </div>
</template>

<style scoped>
/* Copiar estilos de ClientCreatePage.vue — .ccp-header, .ccp-back */
</style>
```

---

### Archivos a modificar

#### `front/src/modules/vets/types/vet.types.ts`

**Cambio:** Agregar los tipos `VetStaffLookupResult`, `VetStaffCreatePayload` y `VetStaffAssignPayload` al final del archivo. También actualizar `VetStaffItem.contacts` del tipo `unknown[]` al tipo tipado `ContactItem[]`.

**Antes (fragmento):**
```typescript
export interface VetStaffItem {
  guid: string
  user: VetStaffUserItem
  role: VetStaffRoleItem
  contacts: unknown[]
  created_at: string
}
```

**Después (fragmento):**
```typescript
export interface VetStaffItem {
  guid: string
  user: VetStaffUserItem
  role: VetStaffRoleItem
  contacts: ContactItem[]   // tipado correctamente — ContactItem ya existe en este archivo
  created_at: string
}
```

**Agregar al final del archivo:**
```typescript
// --- Flujo de incorporación de staff (panel tenant) ---

/** Shape del lookup result de GET /v1/vets/{vet}/staff/lookup */
export interface VetStaffLookupResult {
  found: boolean
  already_linked: boolean | null
  user: {
    guid: string
    first_name: string
    last_name: string
    email: string
  } | null
}

/** Payload para POST /v1/vets/{vet}/staff/new-user */
export interface VetStaffCreatePayload {
  first_name: string
  last_name: string
  email: string
  role_guid: string
  contacts?: ContactFormItem[]
}

/** Payload para POST /v1/vets/{vet}/staff (asignar usuario existente) */
export interface VetStaffAssignPayload {
  user_guid: string
  role_guid: string
  contacts?: ContactFormItem[]
}
```

---

#### `front/src/modules/vets/api/vet-staff.api.ts`

**Cambio:** Agregar al final del archivo las funciones del panel tenant. Las funciones admin existentes no se modifican.

**Agregar al final:**
```typescript
// --- Panel Tenant ---

import type { VetStaffLookupResult, VetStaffCreatePayload, VetStaffAssignPayload } from '../types/vet.types'

export async function lookupVetStaffApi(
  vetSlug: string,
  email: string,
): Promise<VetStaffLookupResult> {
  const res = await http.get<VetStaffLookupResult>(
    `/v1/vets/${vetSlug}/staff/lookup`,
    { params: { email } },
  )
  return res.data
}

export async function createVetStaffApi(
  vetSlug: string,
  payload: VetStaffCreatePayload,
): Promise<VetStaffItem> {
  const res = await http.post<VetStaffItem>(
    `/v1/vets/${vetSlug}/staff/new-user`,
    payload,
  )
  return res.data
}

export async function assignVetStaffApi(
  vetSlug: string,
  payload: VetStaffAssignPayload,
): Promise<VetStaffItem> {
  const res = await http.post<VetStaffItem>(
    `/v1/vets/${vetSlug}/staff`,
    payload,
  )
  return res.data
}
```

**Nota:** Los imports de los tipos ya están disponibles en el mismo módulo. El import de `VetStaffItem` ya está al tope del archivo. Solo se necesita agregar los tipos nuevos al import existente o al bloque de imports del segmento tenant.

---

#### `front/src/modules/clients/components/forms/ClientForm.vue`

**Cambio:** Reemplazar el bloque de contactos inline por `<ContactsInput v-model="localContacts" />`.

**Antes (bloque a reemplazar):**
```html
<FormSection title="Contactos del cliente" subtitle="...">
  <div v-if="localContacts.length > 0" class="contacts-list">
    <div v-for="(contact, idx) in localContacts" :key="idx" class="contact-row">
      <!-- ... toda la lógica inline de contactos ... -->
    </div>
  </div>
  <div v-else class="contacts-empty">Sin contactos agregados.</div>
  <div class="contact-add-actions">
    <a-button size="small" @click.prevent="addEmail">+ Agregar email</a-button>
    <a-button size="small" @click.prevent="addPhone">+ Agregar teléfono</a-button>
  </div>
</FormSection>
```

**Después:**
```html
<FormSection title="Contactos del cliente" subtitle="Emails y teléfonos de contacto. El contacto principal es el que se muestra por defecto.">
  <ContactsInput v-model="localContacts" />
</FormSection>
```

**Agregar en `<script setup>`:**
```typescript
import ContactsInput from '@/components/forms/ContactsInput.vue'
```

**Eliminar de `<script setup>`:** Las funciones `addEmail()`, `addPhone()`, `removeContact()`.

**Mantener:** `localContacts = ref<ContactFormItem[]>([])`, la inicialización en el watch de `initialValues`, y el uso en `onSubmit`. La interfaz de datos no cambia.

**Eliminar de `<style>`:** Las clases CSS de contactos que se mueven a `ContactsInput.vue`.

---

#### `front/src/modules/vets/pages/tenant/VetUsersPage.vue`

**Cambio:** Reemplazar la apertura del modal por navegación a la página de creación. Eliminar import y uso de `CreateTenantUserModal`.

**Antes (fragmento relevante):**
```typescript
import CreateTenantUserModal from '@/modules/users/components/modals/CreateTenantUserModal.vue'
const showCreateTenant = computed({
  get: () => usersUiStore.activeModal === 'createTenant',
  set: (v) => { if (!v) usersUiStore.closeModal() },
})
```

```html
<a-button type="primary" :size="buttonSize" @click="usersUiStore.openModal('createTenant')">
  <PlusOutlined />
  Nuevo usuario
</a-button>
<CreateTenantUserModal v-model="showCreateTenant" />
```

**Después:**
```typescript
import { useRouter } from 'vue-router'
// Eliminar: import CreateTenantUserModal ...
// Eliminar: const showCreateTenant = computed(...)
const router = useRouter()
```

```html
<a-button type="primary" :size="buttonSize" @click="router.push(`/vets/${vetSlug}/usuarios/crear`)">
  <PlusOutlined />
  Nuevo usuario
</a-button>
<!-- Eliminar: <CreateTenantUserModal v-model="showCreateTenant" /> -->
```

**Obtener `vetSlug`:**
```typescript
// Si el store ya tiene vetSlug disponible (hidratado por el guard):
const vetStore = useVetStore()
// Usar: vetStore.vetSlug en el template
// Fallback seguro si el store no lo tiene:
const route   = useRoute()
const vetSlug = computed(() => route.params.vetSlug as string)
```

---

#### `front/src/modules/vets/router/vets-tenant.routes.ts`

**Cambio:** Agregar la ruta `usuarios/crear` con el componente `VetStaffCreatePage.vue`.

**Antes:**
```typescript
{
  path: 'usuarios',
  name: 'vet-tenant-usuarios',
  component: () => import('@/modules/vets/pages/tenant/VetUsersPage.vue'),
  meta: { requiresAuth: true, title: 'Usuarios de la veterinaria' },
},
```

**Después:**
```typescript
{
  path: 'usuarios',
  name: 'vet-tenant-usuarios',
  component: () => import('@/modules/vets/pages/tenant/VetUsersPage.vue'),
  meta: { requiresAuth: true, title: 'Usuarios de la veterinaria' },
},
{
  path: 'usuarios/crear',
  name: 'vet-tenant-usuarios-crear',
  component: () => import('@/modules/vets/pages/tenant/VetStaffCreatePage.vue'),
  meta: { requiresAuth: true, title: 'Incorporar personal al equipo' },
},
```

---

### Tests a generar (frontend, qué cubrir)

1. `useLookupVetStaff`: que `search(email)` habilita la query y `reset()` la deshabilita y limpia el cache con la key `['vet-staff-lookup', ...]`.
2. `useCreateVetStaff`: mutación exitosa invalida `['users']` y `['staff', vetSlug]`.
3. `useAssignVetStaff`: mutación exitosa invalida las mismas keys.
4. `VetStaffLookupForm`: transiciones de estado correctas (idle → searching → not-found, found-linkable, found-linked).
5. `VetStaffLookupForm`: el estado `found-linked` renderiza el botón "Ver personal" y NO los formularios.
6. `ContactsInput`: agregar un email emite `update:modelValue` con el nuevo item; quitar uno también.
7. `VetUsersPage`: el botón "Nuevo usuario" navega a `/vets/:vetSlug/usuarios/crear` (no abre modal).
8. `VetStaffCreatePage`: al `success` navega a `/vets/:vetSlug/usuarios`.

---

## Orden de implementación

### Paso 0 — Renombrar artefactos existentes (antes de crear cualquier cosa nueva)

0a. Renombrar `back/app/Http/Controllers/V1/MemberController.php` → `VetStaffController.php`. Actualizar `class MemberController` → `class VetStaffController`.

0b. Renombrar `back/app/Http/Requests/Members/AssignMemberRequest.php` → `AssignVetStaffRequest.php`. Actualizar clase, cambiar `TENANT_ROLES` → `VET_STAFF_ROLES` (ver R11).

0c. Renombrar `back/app/Http/Requests/Members/ChangeRoleMemberRequest.php` → `ChangeVetStaffRoleRequest.php`. Actualizar clase.

0d. En `back/routes/api/vets.php`: renombrar `prefix('members')` → `prefix('staff')`, y actualizar `MemberController::class` → `VetStaffController::class` en todas las rutas existentes.

0e. Verificar con `php artisan route:list | grep staff` que las rutas existentes siguen funcionando con el nuevo prefijo.

### Pasos de backend

1. **Mover constante `VET_STAFF_ROLES` a `UserProfileService.php`** y agregar import en `AdminAssignStaffRequest`. Correr tests existentes.

2. **Crear `LookupVetStaffRequest.php`** en `back/app/Http/Requests/Members/`.

3. **Crear `CreateVetStaffRequest.php`** en `back/app/Http/Requests/Members/`.

4. **Ampliar `AssignVetStaffRequest.php`** con las reglas de `contacts[]`.

5. **Crear `SendVetStaffInvitationJob.php`** en `back/app/Jobs/`.

6. **Crear `VetStaffInvitationMail.php`** en `back/app/Mail/` + vista Blade `back/resources/views/emails/vet-staff-invitation.blade.php`.

7. **Modificar `UserProfileService.php`**: inyectar `ContactService`, agregar `lookupForVet()` y `createAndAssignVetStaff()`.

8. **Modificar `VetStaffController.php`**: inyectar `ContactService`, agregar métodos `lookup()` y `createAndAssign()`, modificar `store()` para crear contactos opcionales.

9. **Modificar `back/routes/api/vets.php`**: registrar `GET /lookup` y `POST /new-user` antes de las rutas con `{guid}`.

10. **Verificar backend**: correr `php artisan route:list | grep staff` y confirmar que `lookup` y `new-user` aparecen antes que `{guid}`. Correr los feature tests.

### Pasos de frontend

11. **Actualizar `front/src/modules/vets/types/vet.types.ts`**: agregar `VetStaffLookupResult`, `VetStaffCreatePayload`, `VetStaffAssignPayload`; actualizar `VetStaffItem.contacts` a `ContactItem[]`.

12. **Actualizar `front/src/modules/vets/api/vet-staff.api.ts`**: agregar las tres funciones tenant al final del archivo.

13. **Crear `front/src/components/forms/ContactsInput.vue`**. Verificar que compila.

14. **Modificar `front/src/modules/clients/components/forms/ClientForm.vue`**: reemplazar bloque inline por `<ContactsInput v-model="localContacts" />`. Verificar que el formulario de clientes sigue funcionando.

15. **Crear `front/src/modules/users/validators/vet-staff.validator.ts`**.

16. **Crear los tres composables**:
    - `useLookupVetStaff.ts`
    - `useCreateVetStaff.ts`
    - `useAssignVetStaff.ts`

17. **Crear los dos formularios**:
    - `VetStaffAssignForm.vue`
    - `VetStaffNewForm.vue`

18. **Crear `VetStaffLookupForm.vue`** (depende de los dos formularios anteriores).

19. **Crear `VetStaffCreatePage.vue`** en `front/src/modules/vets/pages/tenant/`.

20. **Modificar `vets-tenant.routes.ts`**: agregar la ruta `usuarios/crear` con `VetStaffCreatePage`.

21. **Modificar `VetUsersPage.vue`**: reemplazar apertura de modal por `router.push`.

22. **Verificar compilación TypeScript**: `npm run type-check` desde `front/`.

23. **Test de integración manual**: navegar a `/vets/:vetSlug/usuarios`, hacer clic en "Nuevo usuario", probar los tres flujos (not-found, found-linkable, found-linked).

---

## Riesgos y consideraciones

**R01 — Conflicto de rutas: `lookup` como segmento estático vs. `{guid}` dinámico.**
La ruta `GET /staff/lookup` DEBE estar registrada antes de `DELETE /{guid}` y `PATCH /{guid}/role` en `vets.php`. Si el dev lo registra en orden incorrecto, el router de Laravel intentará resolver `"lookup"` como un guid y fallará con 422 o 404. El paso 9 del orden de implementación incluye verificación explícita con `route:list`.

**R02 — Race condition en `email unique`.**
El flujo es: lookup (verificar que no existe) → formulario → submit. Entre el lookup y el submit puede ocurrir que otro request cree el mismo usuario. La regla `unique:users,email` en `CreateVetStaffRequest` maneja este caso y retorna 422. En el frontend, `parseApiError` lo captura como `fieldErrors.email` y lo muestra en el campo.

**R03 — `ContactsInput` y sincronización con el padre al resetear.**
`ContactsInput` trabaja con una copia local del array. Si el padre resetea el formulario, debe resetear `localContacts` en `VetStaffNewForm` y `VetStaffAssignForm`. El dev debe agregar un watcher que resetee `localContacts` cuando se remonte el componente o cuando haya una señal de reset explícita.

**R04 — `useRoles` en los formularios.**
El composable `useRoles` acepta un objeto de filtros. Se usa `per_page: 100` sin filtro de tipo y se filtra en el frontend por `VET_STAFF_ROLES.includes(r.name)`. Verificar que `useRoles` acepta `per_page` como parámetro. Si el backend tiene paginación default menor a 100 roles, la lista puede quedar truncada.

**R05 — `vetSlug` en `VetUsersPage` al reemplazar el botón del modal.**
Si el `vetTenantGuard` no hidrata `vetSlug` en el store (solo llama `setUserVets`), usar `route.params.vetSlug` como fallback. Verificar el guard antes de decidir qué fuente usar.

**R06 — `VetStaffItem.contacts` actualizado de `unknown[]` a `ContactItem[]`.**
Este cambio afecta los composables admin existentes (`useAdminVetStaff`, etc.) que usan `VetStaffItem`. Si algún template usa `.contacts` con cast a `unknown`, puede fallar en TypeScript. Correr `npm run type-check` después del paso 11 para detectar regresiones.

**R07 — `whatsapp` como tipo de contacto en `ContactsInput` vs. `ClientForm`.**
`ClientForm.vue` solo tiene "Agregar email" y "Agregar teléfono". `ContactsInput` agrega "Agregar WhatsApp". Esto amplía la funcionalidad para `ClientForm` también. Verificado: `StoreContactRequest` sí incluye `'whatsapp'` en el `Rule::in`. No hay riesgo de backend.

**R08 — `UserProfileResource` y carga de relaciones en `store()`.**
El método `store()` modificado agrega `.load(['user', 'role', 'contacts'])`. Verificar que `UserProfileRepository::create()` retorna la instancia con `id` disponible. Esto siempre debería ser así con Eloquent.

**R09 — Impacto multi-tenant.**
Todas las rutas están bajo `Route::prefix('v1/vets/{vet}')->middleware(['auth:sanctum', 'vet.tenant'])`. El middleware garantiza que el tenant del usuario autenticado coincide con la vet de la URL. No hay riesgo de cross-tenant.

**R10 — Sin cambios en `UserProfileRepositoryInterface`.**
Los métodos existentes (`findByEmail` en `UserRepository`, `findForUserAndVet` y `create` en `UserProfileRepository`) son suficientes para los métodos nuevos. No se requieren métodos nuevos en las interfaces.

**R11 — Cambio de `TENANT_ROLES` a `VET_STAFF_ROLES` en `AssignVetStaffRequest`.**
El plan original usaba `UserProfileService::TENANT_ROLES` (que incluye roles `client-*`). El plan actualizado cambia a `VET_STAFF_ROLES`. Verificar si hay casos de uso donde a través del panel tenant se asignen roles `client-*`. Si el endpoint era usado para asignar roles de cliente (improbable pero posible), el cambio es un breaking change. Revisar el código del `MemberController::store()` original antes del paso 0b.

**R12 — Composables: invalidación de query key `['staff', vetSlug]` vs. `['members', vetSlug]`.**
Los composables nuevos invalidan `['staff', vetSlug.value]`. Si `VetUsersPage` usa una query key diferente (ej: `['members', ...]` o `['users', ...]`) para listar el personal, la invalidación no tendrá efecto y la lista no se actualizará. Buscar con Grep la query key usada en `VetUsersPage` o en el composable de lista de personal y alinear.

---

## Pendientes / fuera de alcance

- **Permisos Spatie granulares para usuarios tenant.** El guard actual (`vet.tenant`) solo verifica que el usuario pertenece a la vet. Agregar `can('staff.create')` o similar requiere una iteración de diseño de permisos para el panel tenant.

- **Reenvío de invitación.** Si el token expiró, no hay flujo de reenvío desde este panel. Un endpoint `POST /staff/{guid}/resend-invitation` queda para una iteración futura.

- **Validación de formato de número de teléfono en `ContactsInput`.** El backend valida E.164. El frontend no. Agregar validación Zod con regex E.164 mejoraría la UX pero puede tener falsos negativos.

- **Eliminar `CreateTenantUserModal`.** Una vez que el nuevo flujo esté en producción, verificar con Grep si `CreateTenantUserModal` y `useCreateTenantUser` tienen otros usos antes de eliminarlos.

- **Tests unitarios de `SendVetStaffInvitationJob`** (regeneración de token expirado, manejo de usuario no encontrado).
