# Plan técnico: Capa Core de Multi-Tenancy SAV v2

## Input procesado
Brief informal del usuario (texto libre en el chat), con spec funcional completa inline.

## Resumen ejecutivo
Se implementa la capa de datos y dominio que habilita el modelo multi-tenant del sistema: países, tipos de documento, veterinarias (tenants), perfiles de usuario dentro de un tenant, y contactos polimórficos. No se exponen endpoints REST en esta iteración: el objetivo es tener los modelos, migraciones, seeders y el middleware de resolución de tenant correctos y testeables antes de construir los controladores de gestión. La estrategia es bottom-up: base de datos → enums/traits → modelos → repositorios → servicio de contactos (el único con lógica de negocio no trivial) → middleware de tenant → seeder de países.

---

## Decisiones tomadas

### DEC-01 — Scope de esta iteración: solo capa de datos + middleware, sin endpoints REST

**Decisión:** No se crean controllers, FormRequests ni Resources para `Vet`, `UserProfile`, `Country`, `DocumentType` ni `Contact` en esta iteración.

**Justificación:** La spec es un "core" de multi-tenancy. Exponer los endpoints antes de tener los modelos sólidos y testeados introduce riesgo de tener que romper contratos de API una vez que el dominio se estabilice. Los controllers de gestión de cada entidad son una iteración siguiente. El único componente de "capa HTTP" incluido es el middleware `EnsureUserBelongsToVet`, porque es la pieza que habilita cualquier ruta tenant-scoped futura.

**Alternativa descartada:** Crear CRUD completo en una sola iteración. Descartado por complejidad y riesgo de regresión en la capa de auth existente.

---

### DEC-02 — Namespace de `ContactType` enum

**Decisión:** `App\Enums\ContactType` (backed string enum PHP 8.1+).

**Justificación:** El proyecto usa PHP backed enums sobre string column (confirmado en spec). El namespace `App\Enums\` sigue el estándar del ecosistema Laravel 12. No existe aún ningún enum en el proyecto, se crea la carpeta.

**Alternativa descartada:** Tabla `contact_types` separada. Descartado porque los tipos son un conjunto cerrado definido por el sistema, no configurable por tenant.

---

### DEC-03 — Polimorfismo en `user_profiles.authenticatable_type`: valor a almacenar

**Decisión:** Usar `Relation::morphMap` registrado en `AppServiceProvider::boot()`. La DB almacena aliases cortos (`'vet'`, `'user_profile'`) en lugar del nombre de clase completo.

**Justificación:** SAV v2 está en construcción activa y los namespaces pueden reorganizarse. Con el nombre de clase completo, cualquier rename o movimiento de modelo rompe todos los registros históricos **silenciosamente** (la relación retorna `null` sin excepción). Con morphMap, el alias en DB está desacoplado del namespace de PHP: mover `App\Models\Vet` a otro namespace requiere solo actualizar una línea en AppServiceProvider, sin tocar datos. Adicionalmente, los fallos por morphables no registrados son ruidosos (excepción explícita), lo cual es preferible al silencio del full class name.

**Implementación — agregar en `AppServiceProvider::boot()`:**
```php
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::morphMap([
    'vet'          => \App\Models\Vet::class,
    'user_profile' => \App\Models\UserProfile::class,
]);
```

**Impacto en código:** El repositorio `UserProfileRepositoryEloquent::findForUserAndVet()` debe usar el alias, no la clase:
```php
// Correcto con morphMap
->where('authenticatable_type', 'vet')

// Incorrecto (no usar)
->where('authenticatable_type', Vet::class)
```

El middleware `EnsureUserBelongsToVet` también usa el alias al comparar `authenticatable_type`.

**Regla para el equipo:** Todo modelo nuevo que participe en una relación polimórfica debe registrarse en el morphMap de `AppServiceProvider` en el mismo PR que lo introduce.

**Alternativa descartada:** Nombre de clase completo sin morphMap. Descartado porque es frágil ante refactors de namespace y almacena detalles de implementación PHP en la DB.

---

### DEC-04 — Repositorios para `Country` y `DocumentType`: alcance mínimo

**Decisión:** Los repositorios de `Country` y `DocumentType` exponen solo `all()` y `findByGuid()` en su primera versión. No implementan paginación ni criterios de búsqueda porque en esta iteración no hay endpoints de listado.

**Justificación:** YAGNI. Agregar métodos de listado cuando se creen los controllers correspondientes. El patrón repositorio igual se aplica para no violar la regla arquitectónica del proyecto.

**Alternativa descartada:** Acceso directo al modelo desde el servicio para entidades de referencia (Country, DocumentType). Descartado porque viola la regla dura DEC-08 del sistema.

---

### DEC-05 — Repositorios para `Vet` y `UserProfile`: alcance

**Decisión:** `VetRepositoryInterface` expone `findBySlug()`, `findByGuid()`, `create()`, `update()`. `UserProfileRepositoryInterface` expone `findForUserAndVet()`, `create()`, `findByGuid()`. Métodos de listado/paginación: diferidos a la siguiente iteración.

**Justificación:** El middleware solo necesita `findBySlug()` para Vet y `findForUserAndVet()` para UserProfile. Definir las interfaces con solo lo necesario ahora es correcto; se amplían cuando se necesiten.

---

### DEC-06 — Servicio `ContactService`: enforcea regla `is_primary`

**Decisión:** Existe un `ContactService` con métodos `createContact()` y `updateContact()`. La regla "un solo `is_primary=true` por (contactable, type)" se enforcea en `ContactService`, no en la DB constraint ni en el repositorio.

**Justificación:** La spec lo especifica explícitamente. Una DB unique constraint condicional (partial index) no está soportada universalmente en MySQL 5.7/8.0 de forma limpia con Eloquent. La lógica en el Service permite hacer el update de los otros registros en la misma transacción.

**Alternativa descartada:** DB partial unique index. Descartado por compatibilidad y porque añade complejidad de mantenimiento de migraciones sin ganancia real si el Service ya lo maneja.

---

### DEC-07 — Tabla `contacts`: índice compuesto para la regla `is_primary`

**Decisión:** Agregar índice compuesto `(contactable_type, contactable_id, type)` en la tabla `contacts` para hacer eficiente el query de "poner todos en false antes de setar el nuevo primario".

**Justificación:** El Service hace un `UPDATE ... WHERE contactable_type = ? AND contactable_id = ? AND type = ?` cada vez que `is_primary = true`. Sin índice, eso es full scan de la tabla contacts para cada operación.

---

### DEC-08 — `validated_by` en `vets`: `nullOnDelete` en FK

**Decisión:** La FK `vets.validated_by → users.id` usa `nullOnDelete()` (no cascade).

**Justificación:** La spec lo indica explícitamente ("FK → users nullable, nullOnDelete — auditoría de quién validó"). Tiene sentido: si se borra el usuario que validó, la vet no debe perder su estado de validación.

---

### DEC-09 — `user_profiles.role_id` referencia a tabla `roles`

**Decisión:** FK a `roles.id` (la tabla de Spatie, que en este proyecto es extendida por `App\Models\Role`). El campo `guard_name` del rol asignado es responsabilidad de quien crea el perfil (Service), no de la FK.

**Justificación:** La spec dice "usa roles de Spatie, NO string propio". La tabla `roles` ya existe con `id` y `guid`. La FK garantiza integridad referencial. El `cascadeOnDelete` aplica: si se borra un rol, se borran los perfiles que lo usan (comportamiento aceptable dado que no hay soft deletes).

---

### DEC-10 — Slug de `Vet`: generación automática en el Service, no en el modelo

**Decisión:** El slug se genera en `VetService::create()` a partir del `name`, con sufijo numérico si hay colisión. No se usa un observer ni un hook del modelo.

**Justificación:** La lógica de resolución de colisiones requiere acceso al repositorio (para verificar unicidad). Meterla en el modelo violaría la separación Service/Repository. En el modelo solo se agrega `slug` al `$fillable`.

**Alternativa descartada:** Package `spatie/laravel-sluggable`. Descartado para no agregar dependencias por una funcionalidad que se implementa en 10 líneas en el Service.

---

### DEC-11 — `HasContacts` trait: ubicación y alcance

**Decisión:** El trait vive en `App\Traits\HasContacts`. Expone `contacts()` (morphMany) y `primaryContact(string $type): ?Contact`. No incluye lógica de escritura (eso va en `ContactService`).

**Justificación:** El trait debe ser de solo lectura (relaciones y helpers de query). Toda escritura pasa por el Service para poder enforcar las reglas de negocio.

---

### DEC-12 — Nombres de roles de Spatie para la nueva arquitectura multi-tenant

**Decisión:** Los roles existentes (`super-admin`, `admin`, `operador`) corresponden al panel de administración del SaaS. Los roles de tenant (`vet`, `vet-assistant`, `vet-administrative`, `client-owner`, `client-manager`, `client-administrative`) se agregan al `RoleSeeder` en esta iteración, sin permisos Spatie asociados aún (los permisos tenant-scoped se definen cuando existan los módulos correspondientes).

**Justificación:** `user_profiles.role_id` necesita que estos roles existan en la tabla `roles` para poder ser usados como FK. El seeder de roles es el lugar correcto para crearlos.

---

### DEC-13 — Middleware: resolución de Vet por `{vet:slug}` en el route

**Decisión:** El middleware `EnsureUserBelongsToVet` espera el parámetro de ruta llamado `vet` (resuelto por slug). Usa `Route::current()->parameter('vet')` para obtener el slug del string en la URL, luego busca la Vet en el repositorio. No usa route model binding en el middleware (lo haría a través del repositorio inyectado).

**Justificación:** El route model binding de Laravel usa `getRouteKeyName()` que retorna `guid`, no `slug`. Para resolver por slug en el middleware de forma explícita y controlada (con acceso al repositorio), es más claro recuperar el parámetro raw del route y buscar manualmente.

**Alternativa descartada:** Usar route model binding con `{vet:slug}` syntax de Laravel. Podría funcionar, pero acopla la resolución de tenant al ORM directo en lugar de pasar por el repositorio. El repositorio garantiza que las queries futuras estén centralizadas.

---

## Cambios en BACKEND

### Archivos a crear

---

#### `back/app/Enums/ContactType.php`
**Propósito:** Enum backed string para los tipos de contacto del sistema.

```php
namespace App\Enums;

enum ContactType: string
{
    case Email     = 'email';
    case Phone     = 'phone';
    case Whatsapp  = 'whatsapp';
}
```

**Dependencias inyectadas:** ninguna.

---

#### `back/app/Traits/HasContacts.php`
**Propósito:** Trait de solo lectura para modelos que tienen contactos polimórficos.

```php
namespace App\Traits;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasContacts
{
    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function primaryContact(string $type): ?Contact
    {
        return $this->contacts()
            ->where('type', $type)
            ->where('is_primary', true)
            ->first();
    }
}
```

**Dependencias:** `App\Models\Contact`.

---

#### `back/app/Models/Country.php`
**Propósito:** Modelo de país. Entidad de referencia global (no tenant-scoped).

```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasGuid;

    protected $fillable = ['guid', 'name', 'iso_code', 'phone_prefix'];

    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    public function vets(): HasMany
    {
        return $this->hasMany(Vet::class);
    }
}
```

**Dependencias:** `HasGuid`, `DocumentType`, `Vet`.

---

#### `back/app/Models/DocumentType.php`
**Propósito:** Tipo de documento fiscal vinculado a un país (CUIT, CUIL, RUT, RFC, etc.).

```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentType extends Model
{
    use HasGuid;

    protected $fillable = ['guid', 'country_id', 'name', 'validation_regex'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
```

---

#### `back/app/Models/Vet.php`
**Propósito:** Tenant del sistema. Cada instancia de veterinaria es un tenant aislado.

```php
namespace App\Models;

use App\Traits\HasContacts;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vet extends Model
{
    use HasGuid, HasContacts;

    protected $fillable = [
        'guid', 'name', 'slug', 'country_id', 'document_type_id',
        'tax_id', 'registration_number', 'validated_at', 'validated_by',
        'suspended_at', 'logo_path', 'pdf_title', 'pdf_subtitle',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function userProfiles(): MorphMany
    {
        return $this->morphMany(UserProfile::class, 'authenticatable');
    }

    /** Scope: tenant activo (validado y no suspendido). */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('validated_at')
            ->whereNull('suspended_at');
    }
}
```

**Dependencias:** `HasGuid`, `HasContacts`, `Country`, `DocumentType`, `User`, `UserProfile`.

---

#### `back/app/Models/UserProfile.php`
**Propósito:** Vincula un User a un tenant (Vet u otros futuros) con un rol específico dentro de ese tenant.

```php
namespace App\Models;

use App\Traits\HasContacts;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserProfile extends Model
{
    use HasGuid, HasContacts;

    protected $fillable = [
        'guid', 'user_id', 'authenticatable_type', 'authenticatable_id', 'role_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

**Dependencias:** `HasGuid`, `HasContacts`, `User`, `Role`.

---

#### `back/app/Models/Contact.php`
**Propósito:** Contacto polimórfico (email, teléfono, WhatsApp) para Vet o UserProfile.

```php
namespace App\Models;

use App\Enums\ContactType;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Contact extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'contactable_type', 'contactable_id',
        'type', 'label', 'value', 'is_primary', 'use_for_alerts',
    ];

    protected function casts(): array
    {
        return [
            'type'           => ContactType::class,
            'is_primary'     => 'boolean',
            'use_for_alerts' => 'boolean',
        ];
    }

    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Scope: contactos marcados para envío de alertas. */
    public function scopeForAlerts(Builder $query): Builder
    {
        return $query->where('use_for_alerts', true);
    }
}
```

---

#### `back/app/Contracts/Repositories/CountryRepositoryInterface.php`
**Propósito:** Contrato del repositorio de países.

```php
namespace App\Contracts\Repositories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

interface CountryRepositoryInterface
{
    public function all(): Collection;
    public function findByGuid(string $guid): ?Country;
}
```

---

#### `back/app/Contracts/Repositories/DocumentTypeRepositoryInterface.php`
**Propósito:** Contrato del repositorio de tipos de documento.

```php
namespace App\Contracts\Repositories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;

interface DocumentTypeRepositoryInterface
{
    public function all(): Collection;
    public function findByGuid(string $guid): ?DocumentType;
    public function findByCountry(int $countryId): Collection;
}
```

---

#### `back/app/Contracts/Repositories/VetRepositoryInterface.php`
**Propósito:** Contrato del repositorio de veterinarias (tenants).

```php
namespace App\Contracts\Repositories;

use App\Models\Vet;

interface VetRepositoryInterface
{
    public function findByGuid(string $guid): ?Vet;
    public function findBySlug(string $slug): ?Vet;
    public function create(array $data): Vet;
    public function update(Vet $vet, array $data): Vet;
}
```

---

#### `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php`
**Propósito:** Contrato del repositorio de perfiles de usuario por tenant.

```php
namespace App\Contracts\Repositories;

use App\Models\UserProfile;
use App\Models\Vet;
use App\Models\User;

interface UserProfileRepositoryInterface
{
    public function findByGuid(string $guid): ?UserProfile;
    public function findForUserAndVet(User $user, Vet $vet): ?UserProfile;
    public function create(array $data): UserProfile;
}
```

---

#### `back/app/Contracts/Repositories/ContactRepositoryInterface.php`
**Propósito:** Contrato del repositorio de contactos polimórficos.

```php
namespace App\Contracts\Repositories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

interface ContactRepositoryInterface
{
    public function findByGuid(string $guid): ?Contact;
    public function create(array $data): Contact;
    public function update(Contact $contact, array $data): Contact;
    public function destroy(Contact $contact): bool|null;

    /**
     * Pone is_primary = false en todos los contactos del mismo
     * contactable + type, excepto el excluido por ID.
     */
    public function clearPrimaryForType(
        string $contactableType,
        int    $contactableId,
        string $type,
        ?int   $exceptId = null,
    ): void;
}
```

---

#### `back/app/Repositories/CountryRepositoryEloquent.php`
**Propósito:** Implementación Eloquent de CountryRepositoryInterface.

```php
namespace App\Repositories;

use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

class CountryRepositoryEloquent extends BaseRepositoryEloquent implements CountryRepositoryInterface
{
    protected function model(): string
    {
        return Country::class;
    }

    public function all(): Collection
    {
        return $this->newQuery()->orderBy('name')->get();
    }

    public function findByGuid(string $guid): ?Country
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }
}
```

---

#### `back/app/Repositories/DocumentTypeRepositoryEloquent.php`
**Propósito:** Implementación Eloquent de DocumentTypeRepositoryInterface.

```php
namespace App\Repositories;

use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;

class DocumentTypeRepositoryEloquent extends BaseRepositoryEloquent implements DocumentTypeRepositoryInterface
{
    protected function model(): string
    {
        return DocumentType::class;
    }

    public function all(): Collection
    {
        return $this->newQuery()->with('country')->orderBy('name')->get();
    }

    public function findByGuid(string $guid): ?DocumentType
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByCountry(int $countryId): Collection
    {
        return $this->newQuery()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get();
    }
}
```

---

#### `back/app/Repositories/VetRepositoryEloquent.php`
**Propósito:** Implementación Eloquent de VetRepositoryInterface.

```php
namespace App\Repositories;

use App\Contracts\Repositories\VetRepositoryInterface;
use App\Models\Vet;

class VetRepositoryEloquent extends BaseRepositoryEloquent implements VetRepositoryInterface
{
    protected function model(): string
    {
        return Vet::class;
    }

    public function findByGuid(string $guid): ?Vet
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findBySlug(string $slug): ?Vet
    {
        return $this->newQuery()->where('slug', $slug)->first();
    }

    public function create(array $data): Vet
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Vet $vet, array $data): Vet
    {
        $vet->fill($data);
        $vet->save();
        return $vet;
    }
}
```

---

#### `back/app/Repositories/UserProfileRepositoryEloquent.php`
**Propósito:** Implementación Eloquent de UserProfileRepositoryInterface.

```php
namespace App\Repositories;

use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Models\User;

class UserProfileRepositoryEloquent extends BaseRepositoryEloquent implements UserProfileRepositoryInterface
{
    protected function model(): string
    {
        return UserProfile::class;
    }

    public function findByGuid(string $guid): ?UserProfile
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findForUserAndVet(User $user, Vet $vet): ?UserProfile
    {
        return $this->newQuery()
            ->where('user_id', $user->id)
            ->where('authenticatable_type', Vet::class)
            ->where('authenticatable_id', $vet->id)
            ->first();
    }

    public function create(array $data): UserProfile
    {
        return $this->model->newQuery()->create($data);
    }
}
```

---

#### `back/app/Repositories/ContactRepositoryEloquent.php`
**Propósito:** Implementación Eloquent de ContactRepositoryInterface.

```php
namespace App\Repositories;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class ContactRepositoryEloquent extends BaseRepositoryEloquent implements ContactRepositoryInterface
{
    protected function model(): string
    {
        return Contact::class;
    }

    public function findByGuid(string $guid): ?Contact
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function create(array $data): Contact
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Contact $contact, array $data): Contact
    {
        $contact->fill($data);
        $contact->save();
        return $contact;
    }

    public function destroy(Contact $contact): bool|null
    {
        return $contact->delete();
    }

    public function clearPrimaryForType(
        string $contactableType,
        int    $contactableId,
        string $type,
        ?int   $exceptId = null,
    ): void {
        $query = $this->newQuery()
            ->where('contactable_type', $contactableType)
            ->where('contactable_id', $contactableId)
            ->where('type', $type)
            ->where('is_primary', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['is_primary' => false]);
    }
}
```

---

#### `back/app/Services/ContactService.php`
**Propósito:** Encapsula la lógica de negocio de contactos, incluyendo la regla `is_primary`.

```php
namespace App\Services;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;

class ContactService
{
    public function __construct(
        private ContactRepositoryInterface $contactRepository,
    ) {}

    /**
     * Crea un contacto. Si is_primary = true, limpia los demás del mismo
     * (contactable, type) antes de crear.
     *
     * @param Model  $contactable  Instancia de Vet o UserProfile
     * @param array  $data         Campos validados: type, label?, value, is_primary, use_for_alerts
     */
    public function create(Model $contactable, array $data): Contact
    {
        if (!empty($data['is_primary'])) {
            $this->contactRepository->clearPrimaryForType(
                get_class($contactable),
                $contactable->id,
                $data['type'],
            );
        }

        return $this->contactRepository->create([
            'contactable_type' => get_class($contactable),
            'contactable_id'   => $contactable->id,
            'type'             => $data['type'],
            'label'            => $data['label'] ?? null,
            'value'            => $data['value'],
            'is_primary'       => $data['is_primary'] ?? false,
            'use_for_alerts'   => $data['use_for_alerts'] ?? false,
        ]);
    }

    /**
     * Actualiza un contacto. Si se está seteando is_primary = true,
     * limpia los demás del mismo (contactable, type) excepto el actual.
     *
     * @param Contact $contact  Instancia a actualizar
     * @param array   $data     Campos a modificar (parcial)
     */
    public function update(Contact $contact, array $data): Contact
    {
        $isPrimaryBeingSet = isset($data['is_primary']) && $data['is_primary'] === true;

        if ($isPrimaryBeingSet) {
            $this->contactRepository->clearPrimaryForType(
                $contact->contactable_type,
                $contact->contactable_id,
                $data['type'] ?? $contact->type->value,
                $contact->id,
            );
        }

        return $this->contactRepository->update($contact, $data);
    }

    public function destroy(Contact $contact): void
    {
        $this->contactRepository->destroy($contact);
    }
}
```

**Dependencias inyectadas:** `ContactRepositoryInterface`.

---

#### `back/app/Services/VetService.php`
**Propósito:** Encapsula la lógica de negocio de creación/actualización de veterinarias, incluyendo generación de slug único.

```php
namespace App\Services;

use App\Contracts\Repositories\VetRepositoryInterface;
use App\Models\Vet;
use Illuminate\Support\Str;

class VetService
{
    public function __construct(
        private VetRepositoryInterface $vetRepository,
    ) {}

    public function findByGuid(string $guid): ?Vet
    {
        return $this->vetRepository->findByGuid($guid);
    }

    public function findBySlug(string $slug): ?Vet
    {
        return $this->vetRepository->findBySlug($slug);
    }

    public function create(array $data): Vet
    {
        $data['slug'] = $this->generateUniqueSlug($data['name']);
        return $this->vetRepository->create($data);
    }

    public function update(Vet $vet, array $data): Vet
    {
        // Solo regenera slug si cambia el name
        if (isset($data['name']) && $data['name'] !== $vet->name) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], $vet->id);
        }

        return $this->vetRepository->update($vet, $data);
    }

    /**
     * Genera un slug único. Si hay colisión, agrega sufijo numérico (-2, -3, ...).
     *
     * @param string   $name   Nombre de la veterinaria
     * @param int|null $exceptId  ID a excluir del check de unicidad (para updates)
     */
    private function generateUniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 2;

        while (true) {
            $exists = Vet::where('slug', $slug)
                ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
                ->exists();

            if (!$exists) {
                break;
            }

            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
```

**Dependencias inyectadas:** `VetRepositoryInterface`.

**Nota:** El método `generateUniqueSlug` accede directamente a `Vet::where(...)` para el check de unicidad. Esto es aceptable porque es una query de lectura simple que no justifica un método adicional en el repositorio. Alternativa más pura: agregar `existsBySlug(string $slug, ?int $exceptId): bool` al repositorio. Queda a criterio del dev si prefiere esa separación.

---

#### `back/app/Http/Middleware/EnsureUserBelongsToVet.php`
**Propósito:** Middleware de tenant. Verifica que el usuario autenticado pertenece a la veterinaria del slug en la URL y que dicha veterinaria está activa.

```php
namespace App\Http\Middleware;

use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\VetRepositoryInterface;
use App\Models\Vet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToVet
{
    public function __construct(
        private VetRepositoryInterface         $vetRepository,
        private UserProfileRepositoryInterface $userProfileRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Obtener slug del parámetro de ruta
        $slug = $request->route('vet');

        if (!$slug) {
            return response()->json(['success' => false, 'message' => 'Tenant no especificado.'], 403);
        }

        // 2. Resolver la veterinaria por slug
        $vet = $this->vetRepository->findBySlug($slug);

        if (!$vet) {
            return response()->json(['success' => false, 'message' => 'Veterinaria no encontrada.'], 404);
        }

        // 3. Verificar que la vet está activa (validada y no suspendida)
        if (!$vet->validated_at || $vet->suspended_at) {
            return response()->json(['success' => false, 'message' => 'Veterinaria inactiva.'], 403);
        }

        // 4. Verificar que el usuario tiene un UserProfile en esta vet
        $user    = $request->user();
        $profile = $this->userProfileRepository->findForUserAndVet($user, $vet);

        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'Sin acceso a esta veterinaria.'], 403);
        }

        // 5. Compartir el vet y el profile resueltos con la request
        //    para que los controllers los usen sin volver a buscarlos
        $request->attributes->set('current_vet', $vet);
        $request->attributes->set('current_profile', $profile);

        return $next($request);
    }
}
```

**Dependencias inyectadas:** `VetRepositoryInterface`, `UserProfileRepositoryInterface`.

**Nota para el dev:** Los controllers tenant-scoped recuperan el tenant con `$request->attributes->get('current_vet')` y el perfil con `$request->attributes->get('current_profile')`. No deben volver a resolver por slug.

---

### Archivos a modificar

#### `back/app/Models/User.php`
**Cambio:** Agregar relación `hasMany UserProfile`.

**Antes (resumido):**
```php
public function settings(): HasMany
{
    return $this->hasMany(UserSetting::class);
}
```

**Después (agregar a continuación de `settings()`):**
```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function profiles(): HasMany
{
    return $this->hasMany(UserProfile::class);
}
```

El import `HasMany` ya existe en el archivo (lo usa `settings()`), no duplicar.

---

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar 5 bindings nuevos en el método `register()`.

**Después (agregar al bloque existente de bindings):**
```php
use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Contracts\Repositories\VetRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Repositories\CountryRepositoryEloquent;
use App\Repositories\DocumentTypeRepositoryEloquent;
use App\Repositories\VetRepositoryEloquent;
use App\Repositories\UserProfileRepositoryEloquent;
use App\Repositories\ContactRepositoryEloquent;

// Dentro de register():
$this->app->bind(CountryRepositoryInterface::class, CountryRepositoryEloquent::class);
$this->app->bind(DocumentTypeRepositoryInterface::class, DocumentTypeRepositoryEloquent::class);
$this->app->bind(VetRepositoryInterface::class, VetRepositoryEloquent::class);
$this->app->bind(UserProfileRepositoryInterface::class, UserProfileRepositoryEloquent::class);
$this->app->bind(ContactRepositoryInterface::class, ContactRepositoryEloquent::class);
```

---

#### `back/database/seeders/RoleSeeder.php`
**Cambio:** Agregar los 6 roles de tenant (sin permisos asociados aún).

**Después (agregar al final del método `run()`, antes del cierre):**
```php
$tenantRoles = [
    'vet',
    'vet-assistant',
    'vet-administrative',
    'client-owner',
    'client-manager',
    'client-administrative',
];

foreach ($tenantRoles as $roleName) {
    Role::firstOrCreate(
        ['name' => $roleName, 'guard_name' => 'web'],
        ['guid' => Str::uuid()->toString()],
    );
}
```

---

#### `back/database/seeders/DatabaseSeeder.php`
**Cambio:** Agregar `CountrySeeder` al array del `$this->call([...])`.

**Antes:**
```php
$this->call([
    PermissionSeeder::class,
    RoleSeeder::class,
    SystemSettingSeeder::class,
]);
```

**Después:**
```php
$this->call([
    PermissionSeeder::class,
    RoleSeeder::class,
    SystemSettingSeeder::class,
    CountrySeeder::class,
]);
```

---

### Migrations

#### `back/database/migrations/2026_06_05_000001_create_countries_table.php`

```php
Schema::create('countries', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->string('name', 100);
    $table->char('iso_code', 2)->unique();
    $table->string('phone_prefix', 10);
    $table->timestamps();
});
```

`down()`: `Schema::dropIfExists('countries')`

---

#### `back/database/migrations/2026_06_05_000002_create_document_types_table.php`

```php
Schema::create('document_types', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->foreignId('country_id')
          ->constrained('countries')
          ->cascadeOnDelete();
    $table->string('name', 50);
    $table->string('validation_regex', 200);
    $table->timestamps();
});
```

`down()`: `Schema::dropIfExists('document_types')`

---

#### `back/database/migrations/2026_06_05_000003_create_vets_table.php`

```php
Schema::create('vets', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->string('name', 150);
    $table->string('slug', 150)->unique();
    $table->foreignId('country_id')
          ->constrained('countries')
          ->cascadeOnDelete();
    $table->foreignId('document_type_id')
          ->constrained('document_types')
          ->cascadeOnDelete();
    $table->string('tax_id', 50);
    $table->string('registration_number', 50)->nullable();
    $table->timestamp('validated_at')->nullable();
    $table->foreignId('validated_by')
          ->nullable()
          ->constrained('users')
          ->nullOnDelete();
    $table->timestamp('suspended_at')->nullable();
    $table->string('logo_path', 500)->nullable();
    $table->string('pdf_title', 200)->nullable();
    $table->string('pdf_subtitle', 200)->nullable();
    $table->timestamps();

    // Índice para búsqueda de tenant por slug (usado en middleware)
    $table->index('slug');
    // Índice para listar vets activas
    $table->index(['validated_at', 'suspended_at']);
});
```

`down()`: `Schema::dropIfExists('vets')`

---

#### `back/database/migrations/2026_06_05_000004_create_user_profiles_table.php`

```php
Schema::create('user_profiles', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->foreignId('user_id')
          ->constrained('users')
          ->cascadeOnDelete();
    $table->string('authenticatable_type', 200);
    $table->unsignedBigInteger('authenticatable_id');
    $table->foreignId('role_id')
          ->constrained('roles')
          ->cascadeOnDelete();
    $table->timestamps();

    // Índice compuesto para resolver perfil dado usuario + tenant
    $table->index(['user_id', 'authenticatable_type', 'authenticatable_id']);
    // Índice para queries polimórficas (morphMany desde Vet)
    $table->index(['authenticatable_type', 'authenticatable_id']);
    // Unicidad: un usuario no puede tener dos perfiles en el mismo tenant
    $table->unique(['user_id', 'authenticatable_type', 'authenticatable_id']);
});
```

`down()`: `Schema::dropIfExists('user_profiles')`

**Nota:** La FK de `authenticatable_id` no se puede definir como `foreignId()->constrained()` porque es polimórfica (apunta a distintas tablas). Se define como `unsignedBigInteger` sin constraint de FK formal en DB. La integridad se garantiza por código (en el Service y en el middleware).

---

#### `back/database/migrations/2026_06_05_000005_create_contacts_table.php`

```php
Schema::create('contacts', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->string('contactable_type', 200);
    $table->unsignedBigInteger('contactable_id');
    $table->string('type', 20);  // casteado a ContactType enum en el modelo
    $table->string('label', 100)->nullable();
    $table->string('value', 200);
    $table->boolean('is_primary')->default(false);
    $table->boolean('use_for_alerts')->default(false);
    $table->timestamps();

    // Índice compuesto principal: soporte para la regla is_primary y queries de alertas
    $table->index(['contactable_type', 'contactable_id', 'type']);
    // Índice para queries polimórficas (morphMany)
    $table->index(['contactable_type', 'contactable_id']);
});
```

`down()`: `Schema::dropIfExists('contacts')`

**Nota:** Igual que `user_profiles`, `contactable_id` es polimórfico → no tiene FK formal en DB.

---

### Seeder nuevo

#### `back/database/seeders/CountrySeeder.php`

```php
namespace Database\Seeders;

use App\Models\Country;
use App\Models\DocumentType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Argentina
        $argentina = Country::firstOrCreate(
            ['iso_code' => 'AR'],
            [
                'guid'         => Str::uuid()->toString(),
                'name'         => 'Argentina',
                'phone_prefix' => '54',
            ]
        );

        DocumentType::firstOrCreate(
            ['country_id' => $argentina->id, 'name' => 'CUIT'],
            [
                'guid'             => Str::uuid()->toString(),
                'validation_regex' => '^\d{2}-\d{8}-\d{1}$',
            ]
        );

        DocumentType::firstOrCreate(
            ['country_id' => $argentina->id, 'name' => 'CUIL'],
            [
                'guid'             => Str::uuid()->toString(),
                'validation_regex' => '^\d{2}-\d{8}-\d{1}$',
            ]
        );

        // Uruguay
        $uruguay = Country::firstOrCreate(
            ['iso_code' => 'UY'],
            [
                'guid'         => Str::uuid()->toString(),
                'name'         => 'Uruguay',
                'phone_prefix' => '598',
            ]
        );

        DocumentType::firstOrCreate(
            ['country_id' => $uruguay->id, 'name' => 'RUT'],
            [
                'guid'             => Str::uuid()->toString(),
                'validation_regex' => '^\d{12}$',
            ]
        );
    }
}
```

**Importante:** El seeder usa `WithoutModelEvents` (convención del proyecto). Los `guid` se setean explícitamente en el array de defaults del `firstOrCreate` (segundo argumento), no en el de búsqueda. Esto garantiza que en re-ejecuciones no intente crear con guid duplicado.

---

### Rutas API

En esta iteración **no se crean rutas REST nuevas**. El middleware se registra como alias para ser usado en futuras rutas tenant-scoped.

#### `back/bootstrap/app.php`
**Cambio:** Registrar alias del middleware `EnsureUserBelongsToVet`.

En el método de configuración del kernel (Laravel 12 usa el enfoque de `bootstrap/app.php` con `withMiddleware`), agregar:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'vet.tenant' => \App\Http\Middleware\EnsureUserBelongsToVet::class,
    ]);
})
```

Leer el archivo `back/bootstrap/app.php` antes de modificar para ubicar el lugar exacto. Si ya tiene un bloque `withMiddleware`, agregar el alias dentro del bloque existente.

**Uso futuro en rutas:**
```php
Route::prefix('v1/vets/{vet}')
    ->middleware(['auth:sanctum', 'vet.tenant'])
    ->group(function () {
        // rutas tenant-scoped
    });
```

---

### Permisos Spatie

En esta iteración no se crean permisos nuevos. Los permisos de gestión de vets, usuarios de tenant, etc. se definen en la iteración de controllers.

---

### Contrato del endpoint

No aplica en esta iteración.

---

### Tests a generar

El dev que ejecute este plan debe generar los siguientes tests (ubicación: `back/tests/`):

**Unit — `tests/Unit/Services/ContactServiceTest.php`**
- `test_create_contact_sets_is_primary_and_clears_others`: dado un contactable con dos contactos phone, crear uno nuevo con is_primary=true → los anteriores quedan is_primary=false.
- `test_create_contact_without_is_primary_does_not_affect_others`: crear contacto con is_primary=false no toca los demás.
- `test_update_contact_to_primary_clears_same_type`: actualizar un contacto a is_primary=true limpia los del mismo type en el mismo contactable, pero no toca los de otro type.
- `test_update_contact_to_primary_does_not_clear_itself`: el contacto que se está seteando como primario no se limpia a sí mismo.

**Unit — `tests/Unit/Services/VetServiceTest.php`**
- `test_create_vet_generates_unique_slug`: crear una vet con nombre "Vet Norte" genera slug `vet-norte`.
- `test_create_vet_with_collision_adds_numeric_suffix`: si ya existe `vet-norte`, el segundo genera `vet-norte-2`.
- `test_update_vet_regenerates_slug_only_on_name_change`: actualizar un campo que no es `name` no regenera el slug.

**Unit — `tests/Unit/Models/VetScopeActiveTest.php`**
- `test_scope_active_excludes_unvalidated`: una vet sin validated_at no aparece en `Vet::active()->get()`.
- `test_scope_active_excludes_suspended`: una vet validada pero con suspended_at no aparece.
- `test_scope_active_includes_validated_and_not_suspended`: caso feliz.

**Feature — `tests/Feature/Middleware/EnsureUserBelongsToVetTest.php`**
- `test_middleware_returns_404_if_vet_not_found`: slug inexistente → 404.
- `test_middleware_returns_403_if_vet_not_validated`: vet sin validated_at → 403.
- `test_middleware_returns_403_if_vet_suspended`: vet validada pero suspended_at seteado → 403.
- `test_middleware_returns_403_if_user_has_no_profile`: vet activa pero el usuario no tiene UserProfile en ella → 403.
- `test_middleware_passes_and_sets_request_attributes`: happy path → continúa al siguiente middleware, `current_vet` y `current_profile` están en `$request->attributes`.

**Feature — `tests/Feature/Seeders/CountrySeederTest.php`**
- `test_country_seeder_creates_argentina_and_document_types`: después de `CountrySeeder::run()`, existen AR con CUIT y CUIL.
- `test_country_seeder_creates_uruguay_and_document_types`: existen UY con RUT.
- `test_country_seeder_is_idempotent`: correr dos veces no duplica registros.

---

## Cambios en FRONTEND

No requiere cambios en frontend en esta iteración.

---

## Orden de implementación

1. Crear `back/app/Enums/ContactType.php` (enum, sin dependencias).
2. Crear `back/app/Traits/HasContacts.php` (trait, depende de `Contact` pero solo en type hint, no hay problema de circular).
3. Crear migration `2026_06_05_000001_create_countries_table.php` y correrla (`php artisan migrate`).
4. Crear migration `2026_06_05_000002_create_document_types_table.php` y correrla.
5. Crear migration `2026_06_05_000003_create_vets_table.php` y correrla.
6. Crear migration `2026_06_05_000004_create_user_profiles_table.php` y correrla.
7. Crear migration `2026_06_05_000005_create_contacts_table.php` y correrla.
8. Crear `back/app/Models/Country.php`.
9. Crear `back/app/Models/DocumentType.php`.
10. Crear `back/app/Models/Vet.php`.
11. Crear `back/app/Models/UserProfile.php`.
12. Crear `back/app/Models/Contact.php`.
13. Modificar `back/app/Models/User.php`: agregar relación `profiles()`.
14. Crear las 5 interfaces en `back/app/Contracts/Repositories/` (CountryRepositoryInterface, DocumentTypeRepositoryInterface, VetRepositoryInterface, UserProfileRepositoryInterface, ContactRepositoryInterface).
15. Crear las 5 implementaciones en `back/app/Repositories/` (CountryRepositoryEloquent, DocumentTypeRepositoryEloquent, VetRepositoryEloquent, UserProfileRepositoryEloquent, ContactRepositoryEloquent).
16. Modificar `back/app/Providers/AppServiceProvider.php`: agregar los 5 bindings nuevos.
17. Crear `back/app/Services/ContactService.php`.
18. Crear `back/app/Services/VetService.php`.
19. Crear `back/app/Http/Middleware/EnsureUserBelongsToVet.php`.
20. Leer `back/bootstrap/app.php` y agregar el alias `vet.tenant` para el middleware.
21. Modificar `back/database/seeders/RoleSeeder.php`: agregar los 6 roles de tenant.
22. Crear `back/database/seeders/CountrySeeder.php`.
23. Modificar `back/database/seeders/DatabaseSeeder.php`: incluir `CountrySeeder`.
24. Correr `php artisan db:seed --class=RoleSeeder` para agregar los roles nuevos.
25. Correr `php artisan db:seed --class=CountrySeeder`.
26. Escribir y correr tests (orden sugerido: Unit tests primero, Feature tests después).

---

## Riesgos y consideraciones

### Multi-tenant: scope en queries futuras (crítico)
Todo controller que opere sobre datos de un tenant (animales, protocolos, planes sanitarios, etc.) DEBE filtrar por `vet_id` del tenant resuelto por el middleware. El middleware pone el tenant en `$request->attributes->get('current_vet')`. Si algún controller hace una query sin ese scope, es una violación de seguridad inter-tenant. Este riesgo no existe en esta iteración (no hay controllers), pero debe documentarse como regla para las iteraciones siguientes.

### `validated_by` FK a `users` con `nullOnDelete`
Si se borra el usuario superadmin que validó una vet, `validated_by` queda NULL. La vet permanece válida (lo cual es correcto: la validación ya fue hecha). El dev debe asegurarse de que el campo `validated_by` sea tratado como auditoría opcional, no como condición de actividad.

### `user_profiles` unique constraint: un usuario, un rol por tenant
La constraint `unique(['user_id', 'authenticatable_type', 'authenticatable_id'])` impide que un usuario tenga dos roles dentro del mismo tenant. Si en el futuro se necesita que un usuario tenga múltiples roles en el mismo tenant, habría que cambiar el modelo a una tabla N:M. Este supuesto debe ser validado con el product owner antes de construir los controllers.

### morphMap: modelos nuevos deben registrarse explícitamente
Con morphMap activo, todo modelo nuevo que participe en una relación polimórfica **debe** agregarse al `Relation::morphMap` en `AppServiceProvider::boot()` en el mismo PR. Si se omite, Eloquent lanza una excepción al intentar resolver la relación (fallo ruidoso, detectable en tests). La regla debe documentarse en el README de desarrollo.

### `VetService::generateUniqueSlug` accede al modelo directamente
El método hace `Vet::where('slug', ...)` directo, sin pasar por el repositorio. En escenarios de carga alta y nombres similares puede haber race condition entre el check de unicidad y el insert. Para MVP esta implementación es aceptable. Para producción con alto volumen de registros simultáneos, la solución robusta es un índice UNIQUE en `slug` + manejo de `QueryException` con código `1062` para reintentar con sufijo.

### RoleSeeder: roles de tenant sin permisos
Los 6 roles nuevos (`vet`, `vet-assistant`, etc.) se crean sin permisos Spatie en esta iteración. Las rutas tenant-scoped usarán el middleware de tenant para acceso, y dentro del tenant los permisos se irán agregando módulo por módulo. Esto es intencional. Si el equipo agrega middleware `can:...` en alguna ruta antes de que estos roles tengan permisos, el acceso será denegado silenciosamente. Documentar en el README de desarrollo.

### CountrySeeder: regex almacenados sin delimitadores
Los `validation_regex` en el seeder son patrones sin delimitadores de PHP (ej: `^\d{2}-\d{8}-\d{1}$`). El FormRequest que valide el `tax_id` deberá agregar los delimitadores al usar `preg_match()`. Esto debe quedar claro en el plan de la siguiente iteración (controllers de vets).

### Dependencia circular aparente: `HasContacts` → `Contact` → `contactable_type = UserProfile` → `HasContacts`
No es una dependencia circular real en PHP porque los modelos no se incluyen entre sí en el tiempo de carga. Pero conceptualmente el dev debe notar que `UserProfile` usa `HasContacts` y `Contact::contactable()` puede apuntar de vuelta a `UserProfile`. Esto es correcto y esperado en polimorfismo bidireccional.

---

## Pendientes / fuera de alcance

- **Controllers REST para `Country`, `DocumentType`, `Vet`, `UserProfile`, `Contact`**: próxima iteración. Requieren FormRequests, Resources y rutas.
- **Validación de `tax_id` contra el regex del `DocumentType`**: lógica que va en el FormRequest de creación de vet o en el VetService. Requiere que el controller ya exista.
- **Invitación de usuarios a un tenant (flujo de onboarding)**: requiere feature separada con email de invitación y creación de UserProfile al aceptar.
- **Proceso de validación de vet (superadmin revisa y valida)**: requiere controller de admin + endpoint `PATCH /v1/admin/vets/{guid}/validate`.
- **Suspensión de vet**: similar al punto anterior.
- **Permisos Spatie para roles de tenant**: se definen cuando existan los módulos (animales, protocolos, etc.).
- **Test factories para los modelos nuevos** (`CountryFactory`, `VetFactory`, `UserProfileFactory`, `ContactFactory`): necesarias para los feature tests, pero omitidas del plan para mantener el scope. El dev las crea como paso previo a los tests.
- **Multi-país adicional (MX, PE, CO, CL, BR)**: el seeder solo carga AR y UY. Los demás países se agregan cuando se necesiten. La estructura de `countries` + `document_types` ya soporta todos los países.
