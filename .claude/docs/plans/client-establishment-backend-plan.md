# Plan técnico: Módulos Client y Establishment — Backend Laravel 12

## Input procesado

Brief informal detallado provisto directamente en el chat por el usuario (2026-06-05).
Describe spec funcional completa de tablas, modelos, relaciones, endpoints, validaciones y permisos.

---

## Resumen ejecutivo

Se implementa el módulo `Client` y `Establishment` en el backend de SAV v2 bajo el panel tenant `/v1/vets/{vet}/`. Los clientes son entidades compartidas entre vets (m:n via `client_vet`); el aislamiento multi-tenant se garantiza escopando siempre las queries al pivot. Se siguen exactamente los patrones existentes: Repository/Interface → Service → Controller delgado, `ApiResponseTrait`, `HasGuid`, `HasContacts`, FormRequests por endpoint de escritura, Resources V1. Se crean 3 migraciones, 2 modelos, 2 interfaces, 2 repositorios Eloquent, 2 servicios, 4 FormRequests, 2 Resources, 2 controllers, 1 archivo de rutas, y se modifican `AppServiceProvider`, `PermissionSeeder` y `RoleSeeder`.

---

## Decisiones tomadas

### DEC-01 — Parámetro de ruta `{vet}` resuelto por slug (no guid)

**Decisión:** Mantener el comportamiento actual del middleware `EnsureUserBelongsToVet`, que resuelve la vet por `slug` leyendo `$request->route('vet')` como string raw. El archivo de rutas pasa `{vet}` como segmento de path y el middleware hace la resolución. Los controllers leen la vet de `$request->attributes->get('current_vet')`. No se cambia este patrón.

**Justificación:** El middleware ya implementado funciona así, MemberController y VetController lo usan de la misma forma. Cambiarlo requeriría tocar infraestructura compartida con riesgo de regresión.

**Alternativa descartada:** Route Model Binding por guid en el parámetro `{vet}` — implicaría modificar el middleware y todos los controllers existentes.

---

### DEC-02 — Parámetro de ruta `{client}` en rutas de establecimientos resuelto por guid

**Decisión:** Las rutas anidadas de establishments usan `{client}` como segmento de ruta. El `EstablishmentController` recibe el guid como string y llama al `ClientService` para resolverlo scoped a la vet actual. No se usa Route Model Binding automático para `{client}` (el RMB buscaría por guid en toda la tabla, violando el aislamiento tenant).

**Justificación:** El aislamiento de tenant exige validar que el client pertenece a la vet antes de operar sobre sus establecimientos. Esto no puede hacerse automáticamente con RMB sin un scope global que rompería otras partes.

**Alternativa descartada:** Route Model Binding con scope implícito — más magia, más difícil de auditar, y el patrón existente en el proyecto es resolución manual en controller/service.

---

### DEC-03 — Contactos de un client reutilizan ContactController existente

**Decisión:** Las rutas de contactos de un client se montan bajo `/v1/vets/{vet}/clients/{client}/contacts` y reutilizan el `ContactController` existente. Se extiende el método privado `resolveContactable` del `ContactController` para soportar el parámetro de ruta `{client}`.

**Justificación:** `ContactController.resolveContactable()` ya tiene exactamente este patrón para `{profile}`. Extenderlo evita duplicar 100 líneas de lógica de contactos. El morphMap con `'client'` garantiza que el alias se persiste correctamente en DB.

**Alternativa descartada:** Crear un `ClientContactController` separado — duplicación innecesaria de lógica ya testeada.

---

### DEC-04 — `ClientRepositoryEloquent` recibe `Vet` como parámetro en métodos tenant-scoped

**Decisión:** Los métodos `findByGuidForVet`, `paginateForVet`, y `detachFromVet` reciben `Vet $vet` como primer parámetro. El Service se encarga de pasar la vet que viene del `current_vet` del request. No se usa un scope global ni se inyecta la vet en el constructor del repositorio.

**Justificación:** Repositorios con scope global de tenant en el constructor serían difíciles de testear y rompería el patrón del proyecto (el `VetRepositoryEloquent` no tiene tenant context porque él mismo es el tenant). Pasar la vet como parámetro es explícito y auditable.

**Alternativa descartada:** Scope global `addGlobalScope` en el modelo `Client` — hace que toda query al modelo sea tenant-scoped automáticamente, pero invisibiliza la dependencia y hace difícil queries cross-tenant (por ejemplo, admin panel futuro).

---

### DEC-05 — `tax_id` en `clients` no requiere unicidad global

**Decisión:** No se agrega índice UNIQUE a `clients.tax_id`. Un mismo CUIT puede corresponder a un productor registrado en múltiples vets.

**Justificación:** El modelo de negocio admite que un client esté vinculado a más de una vet. La identidad fiscal no es un identificador global único en SAV; el `guid` cumple esa función.

**Alternativa descartada:** Unique constraint por `(country_id, tax_id)` — implicaría que dos vets no podrían registrar el mismo cliente, lo cual contradice el propósito del pivot `client_vet`.

---

### DEC-06 — DELETE client = desvinculación del pivot, no borrado físico

**Decisión:** `DELETE /v1/vets/{vet}/clients/{guid}` solo elimina la fila en `client_vet`. El registro en `clients` persiste. El controller devuelve 200 con mensaje "Cliente desvinculado correctamente." No se implementa limpieza de clientes huérfanos en esta iteración.

**Justificación:** La spec lo define explícitamente. Un client puede estar vinculado a múltiples vets; borrar el registro afectaría datos de otras vets.

**Alternativa descartada:** Borrado físico condicional (si quedan 0 vets vinculadas, borrar) — fuera de scope según spec, introduce complejidad y riesgo de race condition.

---

### DEC-07 — Contactos iniciales en POST /clients son opcionales y se crean en el Service

**Decisión:** `StoreClientRequest` acepta un campo opcional `contacts` (array de objetos con `type`, `value`, `label?`, `is_primary?`, `use_for_alerts?`). `ClientService::create()` llama a `ContactService::create()` en un loop si `contacts` está presente. Todo dentro de una transacción DB.

**Justificación:** Permite crear client + contactos en una sola llamada API sin forzar N+1 roundtrips desde el frontend. La lógica de contactos ya está en `ContactService`, se reutiliza.

**Alternativa descartada:** Forzar al frontend a crear contactos en llamadas separadas post-creación — peor UX y más complejo de mantener transaccionalidad.

---

### DEC-08 — Índice compuesto en `establishments` para queries por client

**Decisión:** Agregar índice en `(client_id)` en la tabla `establishments`. No se agrega índice compuesto `(client_id, guid)` porque guid ya tiene índice UNIQUE propio que MySQL puede usar para lookups combinados con WHERE client_id.

**Justificación:** El patrón de query más frecuente es `WHERE client_id = ? ORDER BY created_at`. Un índice simple en `client_id` cubre ese caso. El lookup por guid para update/delete usa el índice UNIQUE de guid.

**Alternativa descartada:** Sin índice en client_id — generaría full table scan en clients con muchos establecimientos.

---

### DEC-09 — `profiles()` en `Client` NO se implementa en esta iteración

**Decisión:** La spec menciona `profiles()` como `morphMany(UserProfile::class, 'authenticatable')` en `Client`. Esta relación no se implementa porque implica que un `Client` puede ser authenticatable de un `UserProfile`, lo que requiere cambios en `EnsureUserBelongsToVet` y en el flujo de auth. Queda como pendiente documentado.

**Justificación:** El middleware actual solo conoce `'vet'` como tipo authenticatable. Agregar `'client'` requiere un flujo separado de login por perfil de cliente, fuera del scope de este plan.

**Alternativa descartada:** Implementarlo ahora sin el flujo de auth — crearía una relación en el modelo sin utilidad funcional y podría confundir al equipo.

---

### DEC-10 — `UpdateEstablishmentRequest` usa `sometimes` para todos los campos

**Decisión:** El update de establishment es PATCH-style aunque la ruta sea PUT: todos los campos son `sometimes`. Esto es consistente con `UpdateVetRequest`.

**Justificación:** El frontend puede enviar solo los campos modificados. El modelo Eloquent solo actualiza lo que llega en `fill()`.

**Alternativa descartada:** PUT estricto (todos los campos requeridos) — innecesariamente rígido, inconsistente con el patrón del proyecto.

---

## Cambios en BACKEND

### Archivos a crear

---

#### `back/database/migrations/2026_06_05_000006_create_clients_table.php`

**Propósito:** Crear tabla `clients` con todos los campos de la spec.

```php
Schema::create('clients', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->string('name', 150);
    $table->foreignId('country_id')
          ->constrained('countries')
          ->cascadeOnDelete();
    $table->foreignId('document_type_id')
          ->constrained('document_types')
          ->cascadeOnDelete();
    $table->string('tax_id', 50);
    $table->string('address', 200)->nullable();
    $table->string('city', 100)->nullable();
    $table->string('state', 100)->nullable();
    $table->string('zip_code', 20)->nullable();
    $table->timestamps();

    // Índice para búsqueda por name y tax_id (filtro search del listado)
    $table->index('name');
    $table->index('tax_id');
});
```

**down():** `Schema::dropIfExists('clients');`

---

#### `back/database/migrations/2026_06_05_000007_create_client_vet_table.php`

**Propósito:** Crear tabla pivot `client_vet` con PK compuesta y timestamps.

```php
Schema::create('client_vet', function (Blueprint $table) {
    $table->foreignId('client_id')
          ->constrained('clients')
          ->cascadeOnDelete();
    $table->foreignId('vet_id')
          ->constrained('vets')
          ->cascadeOnDelete();
    $table->timestamps();

    $table->primary(['client_id', 'vet_id']);
});
```

**down():** `Schema::dropIfExists('client_vet');`

**Nota:** El nombre `client_vet` sigue la convención Laravel de orden alfabético para pivots. Se verifica: c < v, correcto.

---

#### `back/database/migrations/2026_06_05_000008_create_establishments_table.php`

**Propósito:** Crear tabla `establishments`.

```php
Schema::create('establishments', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->foreignId('client_id')
          ->constrained('clients')
          ->cascadeOnDelete();
    $table->string('name', 150);
    $table->string('city', 100)->nullable();
    $table->string('zip_code', 20)->nullable();
    $table->decimal('latitude', 10, 8)->nullable();
    $table->decimal('longitude', 11, 8)->nullable();
    $table->timestamps();

    // Índice para listado de establecimientos por client
    $table->index('client_id');
});
```

**Nota sobre decimales:** `decimal(10,8)` permite valores de -90 a +90 (latitud). `decimal(11,8)` permite -180 a +180 (longitud). Ambos soportan negativos por ser DECIMAL signed (MySQL default).

**down():** `Schema::dropIfExists('establishments');`

---

#### `back/app/Models/Client.php`

**Propósito:** Modelo principal de cliente, con HasGuid, HasContacts, relaciones.

```php
namespace App\Models;

use App\Traits\HasContacts;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasGuid, HasContacts;

    protected $fillable = [
        'guid', 'name', 'country_id', 'document_type_id',
        'tax_id', 'address', 'city', 'state', 'zip_code',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function vets(): BelongsToMany
    {
        return $this->belongsToMany(Vet::class, 'client_vet')->withTimestamps();
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}
```

**Nota:** `profiles()` (morphMany a UserProfile) queda fuera de scope (DEC-09). No se agrega al modelo en esta iteración.

---

#### `back/app/Models/Establishment.php`

**Propósito:** Modelo de establecimiento, con HasGuid, pertenece a Client.

```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Establishment extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'client_id', 'name', 'city', 'zip_code', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

---

#### `back/app/Contracts/Repositories/ClientRepositoryInterface.php`

**Propósito:** Contrato del repositorio de clientes.

```php
namespace App\Contracts\Repositories;

use App\Models\Client;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ClientRepositoryInterface
{
    public function findByGuid(string $guid): ?Client;

    /** Busca un client scoped al pivot de esta vet. Retorna null si no pertenece. */
    public function findByGuidForVet(string $guid, Vet $vet): ?Client;

    /** Crea el client y lo vincula a la vet en client_vet. */
    public function createForVet(array $data, Vet $vet): Client;

    public function update(Client $client, array $data): Client;

    /** Pagina los clients de una vet con filtros opcionales. */
    public function paginateForVet(Vet $vet, array $filters, int $perPage): LengthAwarePaginator;

    /** Elimina el registro en client_vet (NO borra el client). */
    public function detachFromVet(Client $client, Vet $vet): void;
}
```

---

#### `back/app/Contracts/Repositories/EstablishmentRepositoryInterface.php`

**Propósito:** Contrato del repositorio de establecimientos.

```php
namespace App\Contracts\Repositories;

use App\Models\Client;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Collection;

interface EstablishmentRepositoryInterface
{
    public function findByGuidForClient(string $guid, Client $client): ?Establishment;

    public function listForClient(Client $client): Collection;

    public function create(array $data): Establishment;

    public function update(Establishment $establishment, array $data): Establishment;

    public function destroy(Establishment $establishment): bool|null;
}
```

---

#### `back/app/Repositories/ClientRepositoryEloquent.php`

**Propósito:** Implementación Eloquent del repositorio de clientes.

```php
namespace App\Repositories;

use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Models\Client;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientRepositoryEloquent extends BaseRepositoryEloquent implements ClientRepositoryInterface
{
    protected function model(): string
    {
        return Client::class;
    }

    public function findByGuid(string $guid): ?Client
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidForVet(string $guid, Vet $vet): ?Client
    {
        // CRÍTICO: filtra por el pivot para garantizar aislamiento de tenant
        return $vet->clients()
            ->where('clients.guid', $guid)
            ->first();
    }

    public function createForVet(array $data, Vet $vet): Client
    {
        $client = $this->model->newQuery()->create($data);
        // Vincular al pivot con timestamps
        $vet->clients()->attach($client->id);
        return $client;
    }

    public function update(Client $client, array $data): Client
    {
        $client->fill($data);
        $client->save();
        return $client;
    }

    public function paginateForVet(Vet $vet, array $filters, int $perPage): LengthAwarePaginator
    {
        // Base: clients de esta vet via pivot
        $query = $vet->clients()->with(['country', 'documentType']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('clients.name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('clients.tax_id', 'like', '%' . $filters['search'] . '%');
            });
        }

        return $query->latest('clients.created_at')->paginate($perPage);
    }

    public function detachFromVet(Client $client, Vet $vet): void
    {
        $vet->clients()->detach($client->id);
    }
}
```

**Nota de implementación:** El método `paginateForVet` usa `$vet->clients()` que ya genera el JOIN con el pivot. Las columnas ambiguas (`name`, `tax_id`, `created_at`) deben prefijarse con `clients.` para evitar ambigüedades cuando Eloquent hace el JOIN con `client_vet`.

---

#### `back/app/Repositories/EstablishmentRepositoryEloquent.php`

**Propósito:** Implementación Eloquent del repositorio de establecimientos.

```php
namespace App\Repositories;

use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Models\Client;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Collection;

class EstablishmentRepositoryEloquent extends BaseRepositoryEloquent implements EstablishmentRepositoryInterface
{
    protected function model(): string
    {
        return Establishment::class;
    }

    public function findByGuidForClient(string $guid, Client $client): ?Establishment
    {
        return $client->establishments()
            ->where('guid', $guid)
            ->first();
    }

    public function listForClient(Client $client): Collection
    {
        return $client->establishments()
            ->latest()
            ->get();
    }

    public function create(array $data): Establishment
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Establishment $establishment, array $data): Establishment
    {
        $establishment->fill($data);
        $establishment->save();
        return $establishment;
    }

    public function destroy(Establishment $establishment): bool|null
    {
        return $establishment->delete();
    }
}
```

---

#### `back/app/Services/ClientService.php`

**Propósito:** Lógica de negocio para gestión de clientes en el contexto de una vet.

```php
namespace App\Services;

use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Models\Client;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientService
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ContactService            $contactService,
    ) {}

    public function paginate(Vet $vet, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->clientRepository->paginateForVet($vet, $filters, $perPage);
    }

    /**
     * Crea el client, lo vincula a la vet, y crea contactos iniciales si se proveen.
     * Todo dentro de una transacción DB.
     */
    public function create(Vet $vet, array $data): Client
    {
        return \DB::transaction(function () use ($vet, $data) {
            $contacts = $data['contacts'] ?? [];
            unset($data['contacts']);

            // Resolver IDs internos desde guids recibidos
            $data = $this->resolveIds($data);

            $client = $this->clientRepository->createForVet($data, $vet);

            foreach ($contacts as $contactData) {
                $this->contactService->create($client, $contactData);
            }

            return $client;
        });
    }

    public function findByGuidForVet(string $guid, Vet $vet): ?Client
    {
        return $this->clientRepository->findByGuidForVet($guid, $vet);
    }

    public function update(Client $client, array $data): Client
    {
        $data = $this->resolveIds($data);
        return $this->clientRepository->update($client, $data);
    }

    /**
     * Desvincula el client de la vet. NO borra el registro de clients.
     */
    public function detach(Client $client, Vet $vet): void
    {
        $this->clientRepository->detachFromVet($client, $vet);
    }

    /**
     * Convierte country_guid y document_type_guid a sus IDs internos.
     * Solo convierte los campos que estén presentes (para soportar updates parciales).
     */
    private function resolveIds(array $data): array
    {
        if (isset($data['country_guid'])) {
            $country = \App\Models\Country::where('guid', $data['country_guid'])->firstOrFail();
            $data['country_id'] = $country->id;
            unset($data['country_guid']);
        }

        if (isset($data['document_type_guid'])) {
            $docType = \App\Models\DocumentType::where('guid', $data['document_type_guid'])->firstOrFail();
            $data['document_type_id'] = $docType->id;
            unset($data['document_type_guid']);
        }

        return $data;
    }
}
```

**Dependencias inyectadas:** `ClientRepositoryInterface`, `ContactService`.

---

#### `back/app/Services/EstablishmentService.php`

**Propósito:** Lógica de negocio para establecimientos, siempre en el contexto de un client verificado.

```php
namespace App\Services;

use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Models\Client;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Collection;

class EstablishmentService
{
    public function __construct(
        private EstablishmentRepositoryInterface $establishmentRepository,
    ) {}

    public function listForClient(Client $client): Collection
    {
        return $this->establishmentRepository->listForClient($client);
    }

    public function create(Client $client, array $data): Establishment
    {
        $data['client_id'] = $client->id;
        return $this->establishmentRepository->create($data);
    }

    public function findByGuidForClient(string $guid, Client $client): ?Establishment
    {
        return $this->establishmentRepository->findByGuidForClient($guid, $client);
    }

    public function update(Establishment $establishment, array $data): Establishment
    {
        return $this->establishmentRepository->update($establishment, $data);
    }

    public function destroy(Establishment $establishment): void
    {
        $this->establishmentRepository->destroy($establishment);
    }
}
```

**Dependencias inyectadas:** `EstablishmentRepositoryInterface`.

---

#### `back/app/Http/Requests/Clients/IndexClientRequest.php`

**Propósito:** Validación del listado paginado de clients.

```php
namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class IndexClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

---

#### `back/app/Http/Requests/Clients/StoreClientRequest.php`

**Propósito:** Validación de creación de client con contactos opcionales.

```php
namespace App\Http\Requests\Clients;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:150'],
            'country_guid'          => ['required', 'string', 'exists:countries,guid'],
            'document_type_guid'    => ['required', 'string', 'exists:document_types,guid'],
            'tax_id'                => ['required', 'string', 'max:50', $this->taxIdRule()],
            'address'               => ['nullable', 'string', 'max:200'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'state'                 => ['nullable', 'string', 'max:100'],
            'zip_code'              => ['nullable', 'string', 'max:20'],

            // Contactos iniciales opcionales
            'contacts'              => ['nullable', 'array', 'max:10'],
            'contacts.*.type'       => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.label'      => ['nullable', 'string', 'max:100'],
            'contacts.*.value'      => ['required', 'string', 'max:200'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Reutiliza exactamente el mismo patrón que StoreVetRequest::taxIdRule().
     */
    private function taxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');
            $docType     = DocumentType::where('guid', $docTypeGuid)->first();

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
            'name.required'               => 'El nombre es obligatorio.',
            'name.max'                    => 'El nombre no puede superar 150 caracteres.',
            'country_guid.required'       => 'El país es obligatorio.',
            'country_guid.exists'         => 'El país seleccionado no existe.',
            'document_type_guid.required' => 'El tipo de documento es obligatorio.',
            'document_type_guid.exists'   => 'El tipo de documento seleccionado no existe.',
            'tax_id.required'             => 'El identificador fiscal es obligatorio.',
            'tax_id.max'                  => 'El identificador fiscal no puede superar 50 caracteres.',
            'contacts.max'                => 'No se pueden agregar más de 10 contactos iniciales.',
            'contacts.*.type.required'    => 'Cada contacto debe tener un tipo.',
            'contacts.*.type.in'          => 'Tipo de contacto inválido. Valores: email, phone, whatsapp.',
            'contacts.*.value.required'   => 'Cada contacto debe tener un valor.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Clients/UpdateClientRequest.php`

**Propósito:** Validación de actualización parcial de client (mismo patrón que UpdateVetRequest).

```php
namespace App\Http\Requests\Clients;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'               => ['sometimes', 'string', 'max:150'],
            'country_guid'       => ['sometimes', 'string', 'exists:countries,guid'],
            'document_type_guid' => ['sometimes', 'string', 'exists:document_types,guid'],
            'tax_id'             => ['sometimes', 'string', 'max:50', $this->taxIdRule()],
            'address'            => ['nullable', 'string', 'max:200'],
            'city'               => ['nullable', 'string', 'max:100'],
            'state'              => ['nullable', 'string', 'max:100'],
            'zip_code'           => ['nullable', 'string', 'max:20'],
        ];
    }

    private function taxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');

            if (!$docTypeGuid) {
                return;
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
            'name.max'                  => 'El nombre no puede superar 150 caracteres.',
            'country_guid.exists'       => 'El país seleccionado no existe.',
            'document_type_guid.exists' => 'El tipo de documento seleccionado no existe.',
            'tax_id.max'                => 'El identificador fiscal no puede superar 50 caracteres.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Establishments/StoreEstablishmentRequest.php`

**Propósito:** Validación de creación de establecimiento.

```php
namespace App\Http\Requests\Establishments;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstablishmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:150'],
            'city'      => ['nullable', 'string', 'max:100'],
            'zip_code'  => ['nullable', 'string', 'max:20'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'       => 'El nombre del establecimiento es obligatorio.',
            'name.max'            => 'El nombre no puede superar 150 caracteres.',
            'latitude.between'    => 'La latitud debe estar entre -90 y 90.',
            'longitude.between'   => 'La longitud debe estar entre -180 y 180.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Establishments/UpdateEstablishmentRequest.php`

**Propósito:** Validación de actualización de establecimiento.

```php
namespace App\Http\Requests\Establishments;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstablishmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:150'],
            'city'      => ['nullable', 'string', 'max:100'],
            'zip_code'  => ['nullable', 'string', 'max:20'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max'          => 'El nombre no puede superar 150 caracteres.',
            'latitude.between'  => 'La latitud debe estar entre -90 y 90.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/ClientResource.php`

**Propósito:** Resource API de client con relaciones cargables condicionalmente.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'          => $this->guid,
            'name'          => $this->name,
            'tax_id'        => $this->tax_id,
            'address'       => $this->address,
            'city'          => $this->city,
            'state'         => $this->state,
            'zip_code'      => $this->zip_code,
            'country'       => new CountryResource($this->whenLoaded('country')),
            'document_type' => new DocumentTypeResource($this->whenLoaded('documentType')),
            'contacts'      => ContactResource::collection($this->whenLoaded('contacts')),
            'establishments'=> EstablishmentResource::collection($this->whenLoaded('establishments')),
            'created_at'    => $this->created_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/EstablishmentResource.php`

**Propósito:** Resource API de establecimiento.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablishmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'      => $this->guid,
            'name'      => $this->name,
            'city'      => $this->city,
            'zip_code'  => $this->zip_code,
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'created_at'=> $this->created_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Controllers/V1/ClientController.php`

**Propósito:** Controller delgado para CRUD de clients en el contexto tenant.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\IndexClientRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\V1\ClientResource;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService,
    ) {}

    public function index(IndexClientRequest $request): JsonResponse
    {
        try {
            $vet     = $request->attributes->get('current_vet');
            $filters = $request->validated();
            $perPage = $request->integer('per_page', 15);

            $paginator = $this->clientService->paginate($vet, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, ClientResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->create($vet, $request->validated());

            $client->load(['country', 'documentType', 'contacts']);

            return $this->makeSuccess(new ClientResource($client), 'Cliente creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(Request $request, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $client->load(['country', 'documentType', 'contacts', 'establishments']);

            return $this->makeSuccess(new ClientResource($client));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateClientRequest $request, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $client = $this->clientService->update($client, $request->validated());

            return $this->makeSuccess(new ClientResource($client), 'Cliente actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $this->clientService->detach($client, $vet);

            return $this->makeSuccess(null, 'Cliente desvinculado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/app/Http/Controllers/V1/EstablishmentController.php`

**Propósito:** Controller delgado para CRUD de establecimientos, siempre scoped a client + vet.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Establishments\StoreEstablishmentRequest;
use App\Http\Requests\Establishments\UpdateEstablishmentRequest;
use App\Http\Resources\V1\EstablishmentResource;
use App\Services\ClientService;
use App\Services\EstablishmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentController extends Controller
{
    public function __construct(
        private ClientService        $clientService,
        private EstablishmentService $establishmentService,
    ) {}

    public function index(Request $request, string $clientGuid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishments = $this->establishmentService->listForClient($client);

            return $this->makeSuccess(EstablishmentResource::collection($establishments));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreEstablishmentRequest $request, string $clientGuid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->create($client, $request->validated());

            return $this->makeSuccess(
                new EstablishmentResource($establishment),
                'Establecimiento creado correctamente.',
                201,
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateEstablishmentRequest $request, string $clientGuid, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->findByGuidForClient($guid, $client);

            if (!$establishment) {
                return $this->makeNotFound('Establecimiento no encontrado.');
            }

            $establishment = $this->establishmentService->update($establishment, $request->validated());

            return $this->makeSuccess(
                new EstablishmentResource($establishment),
                'Establecimiento actualizado correctamente.',
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request, string $clientGuid, string $guid): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->findByGuidForClient($guid, $client);

            if (!$establishment) {
                return $this->makeNotFound('Establecimiento no encontrado.');
            }

            $this->establishmentService->destroy($establishment);

            return $this->makeSuccess(null, 'Establecimiento eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/routes/api/clients.php`

**Propósito:** Archivo de rutas para clients y establishments bajo el panel tenant.

```php
<?php

use App\Http\Controllers\V1\ClientController;
use App\Http\Controllers\V1\ContactController;
use App\Http\Controllers\V1\EstablishmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/vets/{vet}')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {

    // Clients
    Route::prefix('clients')->group(function () {
        Route::get('/',        [ClientController::class, 'index'])->middleware('can:clients.read');
        Route::post('/',       [ClientController::class, 'store'])->middleware('can:clients.create');
        Route::get('/{guid}',  [ClientController::class, 'show'])->middleware('can:clients.read');
        Route::put('/{guid}',  [ClientController::class, 'update'])->middleware('can:clients.update');
        Route::delete('/{guid}', [ClientController::class, 'destroy'])->middleware('can:clients.delete');

        // Contactos de un client
        Route::prefix('/{client}/contacts')->group(function () {
            Route::get('/',       [ContactController::class, 'index'])->middleware('can:clients.read');
            Route::post('/',      [ContactController::class, 'store'])->middleware('can:clients.update');
            Route::put('/{guid}', [ContactController::class, 'update'])->middleware('can:clients.update');
            Route::delete('/{guid}', [ContactController::class, 'destroy'])->middleware('can:clients.update');
        });

        // Establecimientos de un client
        Route::prefix('/{client}/establishments')->group(function () {
            Route::get('/',       [EstablishmentController::class, 'index'])->middleware('can:establishments.read');
            Route::post('/',      [EstablishmentController::class, 'store'])->middleware('can:establishments.create');
            Route::put('/{guid}', [EstablishmentController::class, 'update'])->middleware('can:establishments.update');
            Route::delete('/{guid}', [EstablishmentController::class, 'destroy'])->middleware('can:establishments.delete');
        });
    });
});
```

**Nota sobre parámetro de ruta `{client}`:** En las rutas de contactos y establecimientos anidados, `{client}` llega como string guid. El `ContactController::resolveContactable()` y el `EstablishmentController` lo resuelven manualmente via `ClientService::findByGuidForVet()`.

---

### Archivos a modificar

---

#### `back/app/Providers/AppServiceProvider.php`

**Cambio 1 — morphMap:** Agregar alias `'client'` en `Relation::morphMap()`.

**Antes:**
```php
Relation::morphMap([
    'vet'          => Vet::class,
    'user_profile' => UserProfile::class,
]);
```

**Después:**
```php
use App\Models\Client;

Relation::morphMap([
    'vet'          => Vet::class,
    'user_profile' => UserProfile::class,
    'client'       => Client::class,
]);
```

**Cambio 2 — bindings en register():** Agregar los dos bindings nuevos al final de los existentes.

```php
// Agregar en register():
use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Repositories\ClientRepositoryEloquent;
use App\Repositories\EstablishmentRepositoryEloquent;

$this->app->bind(ClientRepositoryInterface::class, ClientRepositoryEloquent::class);
$this->app->bind(EstablishmentRepositoryInterface::class, EstablishmentRepositoryEloquent::class);
```

---

#### `back/app/Http/Controllers/V1/ContactController.php`

**Cambio:** Extender el método privado `resolveContactable()` para soportar el parámetro `{client}` en la ruta, resolviendo el client scoped a la vet actual.

**Dependencia adicional a inyectar:** `ClientService`.

**Antes (constructor):**
```php
public function __construct(
    private ContactService     $contactService,
    private UserProfileService $userProfileService,
) {}
```

**Después (constructor):**
```php
use App\Services\ClientService;

public function __construct(
    private ContactService     $contactService,
    private UserProfileService $userProfileService,
    private ClientService      $clientService,
) {}
```

**Método `resolveContactable()` — después:**
```php
private function resolveContactable(Request $request): Model
{
    $vet         = $request->attributes->get('current_vet');
    $profileGuid = $request->route('profile');
    $clientGuid  = $request->route('client');

    if ($profileGuid !== null) {
        $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);

        if (!$profile) {
            abort(404, 'Perfil no encontrado en esta veterinaria.');
        }

        return $profile;
    }

    if ($clientGuid !== null) {
        $client = $this->clientService->findByGuidForVet($clientGuid, $vet);

        if (!$client) {
            abort(404, 'Cliente no encontrado en esta veterinaria.');
        }

        return $client;
    }

    return $vet;
}
```

**Nota de seguridad:** Este cambio garantiza que `ContactController` solo accede a clientes que pertenecen al tenant actual. Si `$clientGuid` no pertenece a `$vet`, retorna 404, nunca expone el contacto.

---

#### `back/app/Models/Vet.php`

**Cambio:** Agregar relación `clients()` (inversa del pivot).

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Agregar método:
public function clients(): BelongsToMany
{
    return $this->belongsToMany(Client::class, 'client_vet')->withTimestamps();
}
```

**Nota:** Requiere también `use App\Models\Client;` en el archivo.

---

#### `back/database/seeders/PermissionSeeder.php`

**Cambio:** Agregar los 8 nuevos permisos al array `$permissions`.

**Después del último permiso existente (`'vets.validate'`), agregar:**
```php
'clients.read',
'clients.create',
'clients.update',
'clients.delete',
'establishments.read',
'establishments.create',
'establishments.update',
'establishments.delete',
```

---

#### `back/database/seeders/RoleSeeder.php`

**Cambio:** El `super-admin` recibe todos los permisos por `syncPermissions(Permission::all())`, lo que incluye automáticamente los nuevos. No requiere cambio de código, pero se debe correr el seeder después de `PermissionSeeder`.

Los roles `vet`, `vet-assistant` y demás roles tenant no reciben estos permisos en esta iteración (pendiente, definido en spec).

**No se modifica el archivo**, pero se documenta el orden de ejecución: `PermissionSeeder` debe correr antes que `RoleSeeder`.

---

### Migraciones

| # | Archivo | Tabla | Orden |
|---|---------|-------|-------|
| 1 | `2026_06_05_000006_create_clients_table.php` | `clients` | Depende de `countries`, `document_types` |
| 2 | `2026_06_05_000007_create_client_vet_table.php` | `client_vet` | Depende de `clients`, `vets` |
| 3 | `2026_06_05_000008_create_establishments_table.php` | `establishments` | Depende de `clients` |

Todas son reversibles: el `down()` usa `Schema::dropIfExists()`. El orden de timestamps en el nombre garantiza la ejecución correcta por `php artisan migrate`.

---

### Rutas API

| Método | Path | Controller@Action | Middleware adicional |
|--------|------|-------------------|---------------------|
| GET | `/v1/vets/{vet}/clients` | `ClientController@index` | `can:clients.read` |
| POST | `/v1/vets/{vet}/clients` | `ClientController@store` | `can:clients.create` |
| GET | `/v1/vets/{vet}/clients/{guid}` | `ClientController@show` | `can:clients.read` |
| PUT | `/v1/vets/{vet}/clients/{guid}` | `ClientController@update` | `can:clients.update` |
| DELETE | `/v1/vets/{vet}/clients/{guid}` | `ClientController@destroy` | `can:clients.delete` |
| GET | `/v1/vets/{vet}/clients/{client}/contacts` | `ContactController@index` | `can:clients.read` |
| POST | `/v1/vets/{vet}/clients/{client}/contacts` | `ContactController@store` | `can:clients.update` |
| PUT | `/v1/vets/{vet}/clients/{client}/contacts/{guid}` | `ContactController@update` | `can:clients.update` |
| DELETE | `/v1/vets/{vet}/clients/{client}/contacts/{guid}` | `ContactController@destroy` | `can:clients.update` |
| GET | `/v1/vets/{vet}/clients/{client}/establishments` | `EstablishmentController@index` | `can:establishments.read` |
| POST | `/v1/vets/{vet}/clients/{client}/establishments` | `EstablishmentController@store` | `can:establishments.create` |
| PUT | `/v1/vets/{vet}/clients/{client}/establishments/{guid}` | `EstablishmentController@update` | `can:establishments.update` |
| DELETE | `/v1/vets/{vet}/clients/{client}/establishments/{guid}` | `EstablishmentController@destroy` | `can:establishments.delete` |

Todos bajo middleware base: `auth:sanctum` + `vet.tenant`.

**Nota sobre parámetro {guid} en contactos de client:** El ContactController ya usa `{guid}` para identificar el contacto. En la ruta `/clients/{client}/contacts/{guid}`, `{client}` es el guid del client y `{guid}` es el guid del contacto. No hay colisión porque son nombres de parámetro distintos.

---

### Permisos Spatie

| Permiso | Guard | Roles que lo reciben ahora |
|---------|-------|---------------------------|
| `clients.read` | `web` | `super-admin` (via `Permission::all()`) |
| `clients.create` | `web` | `super-admin` |
| `clients.update` | `web` | `super-admin` |
| `clients.delete` | `web` | `super-admin` |
| `establishments.read` | `web` | `super-admin` |
| `establishments.create` | `web` | `super-admin` |
| `establishments.update` | `web` | `super-admin` |
| `establishments.delete` | `web` | `super-admin` |

Los roles `vet`, `vet-assistant`, `vet-administrative`, `client-owner`, `client-manager`, `client-administrative` NO reciben estos permisos en esta iteración. Se asignarán en una iteración posterior cuando se defina la granularidad de permisos intra-tenant.

**Seeder donde se agregan:** `back/database/seeders/PermissionSeeder.php`.

---

### Contrato de endpoints

#### GET /v1/vets/{vet}/clients

Request (query params):
```json
{
  "search": "string|nullable — filtra por name o tax_id",
  "per_page": "integer|nullable|min:1|max:100 — default 15",
  "page": "integer|nullable — default 1"
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "uuid",
        "name": "Razón Social S.A.",
        "tax_id": "20-12345678-9",
        "address": "Ruta 8 km 45",
        "city": "Pergamino",
        "state": "Buenos Aires",
        "zip_code": "2700",
        "country": { "guid": "uuid", "name": "Argentina", "iso_code": "AR", "phone_prefix": "+54" },
        "document_type": { "guid": "uuid", "name": "CUIT", "country": {...} },
        "contacts": [],
        "establishments": [],
        "created_at": "2026-06-05T00:00:00.000000Z"
      }
    ],
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

---

#### POST /v1/vets/{vet}/clients

Request body:
```json
{
  "name": "required|string|max:150",
  "country_guid": "required|exists:countries,guid",
  "document_type_guid": "required|exists:document_types,guid",
  "tax_id": "required|string|max:50 + regex del document_type",
  "address": "nullable|string|max:200",
  "city": "nullable|string|max:100",
  "state": "nullable|string|max:100",
  "zip_code": "nullable|string|max:20",
  "contacts": [
    {
      "type": "email|phone|whatsapp",
      "label": "nullable|string|max:100",
      "value": "required|string|max:200",
      "is_primary": "nullable|boolean",
      "use_for_alerts": "nullable|boolean"
    }
  ]
}
```

Response 201:
```json
{
  "success": true,
  "data": { /* ClientResource con country, documentType, contacts cargados */ },
  "message": "Cliente creado correctamente."
}
```

Errores posibles:

| HTTP | Cuándo |
|------|--------|
| 422 | Validación fallida (tax_id inválido, document_type_guid inexistente, etc.) |
| 403 | Sin permiso `clients.create` |
| 403 | Usuario no pertenece al tenant |

---

#### GET /v1/vets/{vet}/clients/{guid}

Response 200:
```json
{
  "success": true,
  "data": {
    /* ClientResource completo con country, documentType, contacts, establishments */
  }
}
```

| HTTP | Cuándo |
|------|--------|
| 404 | Client no existe o no pertenece al tenant |
| 403 | Sin permiso `clients.read` |

---

#### PUT /v1/vets/{vet}/clients/{guid}

Request body: todos los campos de `StoreClientRequest` pero con `sometimes` (excepto `contacts` que no aplica en update).

Response 200:
```json
{
  "success": true,
  "data": { /* ClientResource */ },
  "message": "Cliente actualizado correctamente."
}
```

---

#### DELETE /v1/vets/{vet}/clients/{guid}

Response 200:
```json
{
  "success": true,
  "data": null,
  "message": "Cliente desvinculado correctamente."
}
```

| HTTP | Cuándo |
|------|--------|
| 404 | Client no existe o no pertenece al tenant |
| 403 | Sin permiso `clients.delete` |

---

#### POST /v1/vets/{vet}/clients/{client}/establishments

Request body:
```json
{
  "name": "required|string|max:150",
  "city": "nullable|string|max:100",
  "zip_code": "nullable|string|max:20",
  "latitude": "nullable|numeric|between:-90,90",
  "longitude": "nullable|numeric|between:-180,180"
}
```

Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid",
    "name": "La Llovizna",
    "city": "Pergamino",
    "zip_code": "2700",
    "latitude": -33.891111,
    "longitude": -60.567222,
    "created_at": "2026-06-05T00:00:00.000000Z"
  },
  "message": "Establecimiento creado correctamente."
}
```

---

### Tests a generar (qué cubrir, no el código)

#### Feature tests — ClientController

- **index — happy path:** Usuario con `clients.read` lista clients de su vet; verifica que solo aparecen los de esa vet (no los de otra).
- **index — filtro search:** Filtra por name parcial; filtra por tax_id parcial; ambos en combinación.
- **index — paginación:** Per page custom, página 2.
- **index — sin permiso:** 403 si usuario no tiene `clients.read`.
- **store — happy path:** Crea client con datos mínimos; verifica registro en `clients` y en `client_vet`.
- **store — con contactos iniciales:** Verifica que los contactos se crean en `contacts` polimórficamente asociados al client.
- **store — tax_id inválido:** 422 con mensaje del DocumentType.
- **store — document_type_guid de otro país:** Validación rechaza si el document_type no existe.
- **show — happy path:** Retorna client con establishments y contacts cargados.
- **show — client de otra vet:** 404 (no expone datos cross-tenant).
- **update — happy path:** Actualiza name; verifica que tax_id válido pasa.
- **update — tax_id inválido con nuevo document_type_guid:** 422.
- **destroy — happy path:** Solo elimina fila en `client_vet`; el registro en `clients` persiste.
- **destroy — client de otra vet:** 404.

#### Feature tests — EstablishmentController

- **index — happy path:** Lista establecimientos del client scoped al tenant.
- **index — client de otra vet:** 404 en el client; nunca llega al listado.
- **store — happy path:** Crea establecimiento con lat/long negativos (hemisferio sur).
- **store — latitud fuera de rango:** 422.
- **update — happy path:** Actualiza solo `name`.
- **destroy — happy path:** Elimina el registro de `establishments`.
- **destroy — establishment de otro client:** 404 (aunque el guid exista en otro client).

#### Feature tests — ContactController (extensión para clients)

- **store contacto en client:** Verifica que `contactable_type = 'client'` en la DB (usa el alias del morphMap).
- **update contacto de client de otra vet:** 404.
- **destroy contacto — cross-tenant:** 404.

#### Unit tests — ClientService

- **resolveIds:** Convierte correctamente `country_guid` a `country_id`; idem `document_type_guid`.
- **create en transacción:** Si falla la creación de un contacto, el client no se persiste (rollback).
- **detach:** Verifica que solo elimina el pivot, no el client.

#### Unit tests — ClientRepositoryEloquent

- **paginateForVet:** Verifica que el query incluye JOIN con `client_vet` y filtra por `vet_id`.
- **findByGuidForVet:** Verifica aislamiento: mismo guid con vet distinta retorna null.

---

## Cambios en FRONTEND

No requiere cambios en frontend en esta iteración. El plan cubre exclusivamente el backend. El frontend se planificará en una iteración separada una vez que el contrato de API esté validado con tests.

---

## Orden de implementación

1. Crear migration `2026_06_05_000006_create_clients_table.php`.
2. Crear migration `2026_06_05_000007_create_client_vet_table.php`.
3. Crear migration `2026_06_05_000008_create_establishments_table.php`.
4. Ejecutar `php artisan migrate` y verificar que las 3 tablas se crean correctamente.
5. Crear modelo `back/app/Models/Client.php` con `HasGuid`, `HasContacts` y todas las relaciones.
6. Crear modelo `back/app/Models/Establishment.php` con `HasGuid` y relación `client()`.
7. Modificar `back/app/Models/Vet.php`: agregar relación `clients()` BelongsToMany.
8. Modificar `back/app/Providers/AppServiceProvider.php`:
   - Agregar `'client' => Client::class` al morphMap en `boot()`.
   - Agregar bindings de `ClientRepositoryInterface` y `EstablishmentRepositoryInterface` en `register()`.
9. Crear `back/app/Contracts/Repositories/ClientRepositoryInterface.php`.
10. Crear `back/app/Contracts/Repositories/EstablishmentRepositoryInterface.php`.
11. Crear `back/app/Repositories/ClientRepositoryEloquent.php`.
12. Crear `back/app/Repositories/EstablishmentRepositoryEloquent.php`.
13. Crear `back/app/Services/ClientService.php`.
14. Crear `back/app/Services/EstablishmentService.php`.
15. Agregar los 8 permisos nuevos en `back/database/seeders/PermissionSeeder.php`.
16. Ejecutar `php artisan db:seed --class=PermissionSeeder` y luego `php artisan db:seed --class=RoleSeeder` para que `super-admin` los reciba.
17. Crear `back/app/Http/Requests/Clients/IndexClientRequest.php`.
18. Crear `back/app/Http/Requests/Clients/StoreClientRequest.php`.
19. Crear `back/app/Http/Requests/Clients/UpdateClientRequest.php`.
20. Crear `back/app/Http/Requests/Establishments/StoreEstablishmentRequest.php`.
21. Crear `back/app/Http/Requests/Establishments/UpdateEstablishmentRequest.php`.
22. Crear `back/app/Http/Resources/V1/ClientResource.php`.
23. Crear `back/app/Http/Resources/V1/EstablishmentResource.php`.
24. Crear `back/app/Http/Controllers/V1/ClientController.php`.
25. Crear `back/app/Http/Controllers/V1/EstablishmentController.php`.
26. Modificar `back/app/Http/Controllers/V1/ContactController.php`: agregar inyección de `ClientService` y extender `resolveContactable()`.
27. Crear `back/routes/api/clients.php` con todas las rutas.
28. Verificar que `back/routes/api.php` incluye automáticamente el nuevo archivo (usa glob, ya cubre el directorio).
29. Ejecutar `php artisan route:list | grep clients` para verificar que las 13 rutas están registradas.
30. Ejecutar tests feature y unit.

---

## Riesgos y consideraciones

### Riesgo 1 — Ambigüedad de columnas en paginateForVet (CRÍTICO)

Cuando `$vet->clients()` genera el JOIN con `client_vet`, columnas como `created_at`, `name`, y `id` existen en ambas tablas. El `ORDER BY clients.created_at` y los `WHERE clients.name` deben usar el prefijo de tabla explícitamente. Si se omite, MySQL puede ordenar por `client_vet.created_at` (que es la fecha de vinculación, no de creación del client). Esto es un bug silencioso. El repositorio ya lo contempla con `latest('clients.created_at')` y `clients.name` en el filtro search — el dev debe verificarlo con `->toSql()` antes de cerrar el paso 11.

### Riesgo 2 — Colisión de parámetro `{guid}` en rutas de contactos de client

En la ruta `PUT /v1/vets/{vet}/clients/{client}/contacts/{guid}`, el parámetro `{guid}` es el guid del contacto y `{client}` es el guid del client. El ContactController usa `$request->route('profile')` para perfiles y ahora usará `$request->route('client')` para clients. Si en el futuro se agregan más contactables anidados, el patrón de `resolveContactable()` con múltiples if-elseif puede volverse frágil. Se documenta como deuda técnica: considerar un patrón Strategy o un resolvedor de contactables inyectable.

### Riesgo 3 — Seeder idempotente pero orden dependiente

`PermissionSeeder` usa `firstOrCreate`, es idempotente. `RoleSeeder` usa `syncPermissions(Permission::all())`, que requiere que los permisos ya existan. Si se corre `RoleSeeder` sin haber corrido `PermissionSeeder` primero, `super-admin` no recibirá los nuevos permisos. El `DatabaseSeeder` debe garantizar el orden. Se recomienda verificar `DatabaseSeeder.php` y agregar ambos si no están.

### Riesgo 4 — morphMap y datos existentes en contacts

Si en el futuro se crearan contactos de clients antes de agregar `'client' => Client::class` al morphMap, los registros quedarían con `contactable_type = 'App\Models\Client'` (FQCN) en lugar del alias `'client'`. El `ContactService::resolveContactableType()` ya maneja esto con fallback al FQCN, pero las queries directas a la tabla quedarían inconsistentes. El morphMap debe registrarse ANTES de que cualquier contacto de client se cree. El orden del plan garantiza esto (paso 8 antes del paso 27).

### Riesgo 5 — `profiles()` en Client no implementada (DEC-09)

La spec menciona `profiles()` como relación en `Client`. Si en el futuro se implementa el login de tipo "client-owner" o "client-manager" con perfil authenticatable en un client, el middleware `EnsureUserBelongsToVet` deberá extenderse para soportar `'client'` como tipo de tenant. Esto es un cambio de infraestructura no trivial. Se documenta explícitamente para no sorprender al equipo en esa iteración.

### Riesgo 6 — Limpieza de clients huérfanos

Después de `detachFromVet`, un client puede quedar sin ninguna vet vinculada. No hay limpieza automática. En una base de datos grande esto puede acumular registros. Fuera de scope por decisión de la spec, pero se recomienda agregar una tarea Artisan de limpieza diferida en la siguiente iteración.

### Riesgo 7 — Multi-país (bajo, bien diseñado)

El modelo de `clients` usa `country_id` + `document_type_id` de la misma forma que `vets`. El `taxIdRule` valida contra el `validation_regex` del `DocumentType` que ya es por país. No hay lógica hardcodeada de Argentina. El riesgo es bajo. Considerar en el futuro si se necesita que `document_type_guid` pertenezca al mismo `country_id` que `country_guid` (validación cruzada) — actualmente no se valida.

### Riesgo 8 — Race condition en detach + delete de vet

Si una vet se suspende o elimina en el futuro mientras tiene clients vinculados, la FK en `client_vet.vet_id` con `cascadeOnDelete` eliminará automáticamente las filas del pivot. Los clients quedarían huérfanos de esa vet pero no se borrarían. Este comportamiento es correcto según el dominio, pero debe estar documentado y testeado.

---

## Pendientes / fuera de alcance

1. **`profiles()` en `Client`** — Relación morphMany de UserProfile con authenticatable Client. Requiere cambios en el flujo de auth y en el middleware. Iteración posterior.

2. **Permisos de roles tenant** — Los roles `vet`, `vet-assistant`, `vet-administrative`, `client-owner`, `client-manager`, `client-administrative` no reciben permisos de clients/establishments en esta iteración. Requiere definición de granularidad de permisos intra-tenant.

3. **Limpieza de clients huérfanos** — Tarea Artisan o job diferido para borrar clients sin vets vinculadas. Iteración posterior.

4. **Filtros adicionales en listado de clients** — Filtro por country, por document_type, por ciudad. No mencionado en la spec; se puede agregar al repositorio sin cambios de arquitectura.

5. **Frontend** — Stores Pinia, composables, vistas Vue 3 para clients y establishments. Plan separado.

6. **Validación cruzada country/document_type en StoreClientRequest** — Verificar que `document_type_guid` pertenece al mismo país que `country_guid`. No está en la spec original de `StoreVetRequest`; se posterga para mantener consistencia con el patrón existente.

7. **Exportación de clients** — Fuera de scope de este plan. El módulo de exports existente puede extenderse en una iteración posterior.
