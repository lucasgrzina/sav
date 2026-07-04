# Plan técnico: Control de vigencia y historial de passwords

## Input procesado

Descripción técnica provista directamente en el prompt (sin archivo de spec/ticket en disco). El input define cuatro funcionalidades: SystemSettings EAV global, tabla de historial de passwords, modificación del flujo de login para detectar expiración, y modificación del flujo de cambio de password para verificar historial.

---

## Resumen ejecutivo

Se implementa un sistema de política de passwords con dos configuraciones globales (expiración en meses e historial de N versiones anteriores), almacenadas en una nueva tabla `system_settings` con patrón EAV idéntico al de `user_settings`. El cambio de password (en cualquiera de sus tres flujos: usuario propio, forgot-password, reset admin) verifica el historial antes de persistir y guarda la nueva entrada después. El login detecta si el password expiró y devuelve `must_change_password: true` junto al token normal, para que el frontend pueda forzar el cambio sin bloquear la sesión. Los cambios tocan backend en las capas Service, Repository, Controller y Seeder, y frontend en el módulo `auth` (store y composable de login) y en un nuevo módulo `system-settings` para el CRUD admin.

---

## Decisiones tomadas

**DEC-01 — Dónde vive la lógica de verificación de historial**
Decisión: en `UserRepositoryEloquent::updatePassword`, que ya tiene el placeholder `storePasswordHistory`. La lógica de historial es una responsabilidad de persistencia (leer hashes, comparar, escribir) y no de negocio de dominio. El Service no cambia su firma; el repositorio es el punto único de control para todos los flujos que llaman a `updatePassword`.
Alternativa descartada: mover la lógica al Service. Se descarta porque implica que el Service conoce detalles de hashing y de la tabla `user_password_histories`, rompiendo la separación de capas.

**DEC-02 — Dónde vive la lógica de verificación de expiración**
Decisión: en `AuthService::login`, inmediatamente después de generar el token. El Service ya tiene el contexto del usuario autenticado y es responsable de construir el payload de respuesta del login. La verificación es de negocio (leer un setting global + comparar fechas), no de persistencia, por lo que va en el Service y no en el Repository.
Alternativa descartada: un middleware. Se descarta porque el middleware operaría en cada request autenticado, lo que es demasiado agresivo. La detección en el login es el momento correcto y la respuesta con el token incluido permite al frontend hacer el cambio sin perder la sesión.

**DEC-03 — Cómo leer la configuración global en el código**
Decisión: inyectar `SystemSettingRepositoryInterface` en `AuthService` y en `UserRepositoryEloquent`. El repositorio expone un método `getValue(string $code, mixed $default = null): mixed` que lee la tabla y castea según el campo `type`. No se usa `config()` ni `Cache` en primera iteración — el cacheado se puede agregar después sin cambiar la firma.
Alternativa descartada: usar `Cache::remember`. Se deja fuera del alcance de esta iteración para no agregar complejidad innecesaria ahora.

**DEC-04 — Excepción para password en historial**
Decisión: lanzar `\Illuminate\Validation\ValidationException` con clave `password` (igual que el resto de errores de validación del sistema). No crear una excepción custom. El `ResponseHelper::makeFromException` ya la maneja devolviendo 422, y el frontend ya procesa `fieldErrors` en `useChangePassword`.
Alternativa descartada: excepción custom `PasswordReusedException`. Se descarta porque agrega una clase sin beneficio concreto dado que `ResponseHelper` no tiene manejo diferenciado para excepciones custom en este momento.

**DEC-05 — Nombre del permiso para system-settings**
Decisión: `system-settings.manage` (permiso único de lectura + escritura). El CRUD de configuraciones globales es una operación de superadmin, no tiene sentido separar lectura de escritura para este caso.
Alternativa descartada: `system-settings.read` + `system-settings.write`. Se descarta por YAGNI — este módulo lo usa exclusivamente el superadmin.

**DEC-06 — SystemSetting no lleva guid**
Decisión: `SystemSetting` no implementa `HasGuid`. La tabla `system_settings` se accede siempre por `code` (clave de negocio), nunca por un identificador opaco en URL. El endpoint de update usa `PATCH /v1/system-settings/{code}` con el `code` como parámetro de ruta (string corto, legible, inmutable).
Alternativa descartada: agregar guid igual que otros modelos. Se descarta porque no hay razón de negocio para exponer un UUID opaco cuando el `code` ya es el identificador natural y único.

**DEC-07 — Módulo frontend para system-settings**
Decisión: crear un nuevo módulo `front/src/modules/system-settings/` con su propia página de administración, separado del módulo `settings` (que es de preferencias personales del usuario). Rutas bajo `/admin/system-settings`.
Alternativa descartada: agregar system-settings al módulo `settings` existente. Se descarta porque son responsabilidades distintas: `settings` es EAV por usuario, `system-settings` es configuración global de la plataforma.

**DEC-08 — Flujo frontend para must_change_password**
Decisión: en `auth.store.ts`, cuando la respuesta de login incluye `must_change_password: true`, guardar el token en el store (igual que un login normal) y redirigir a una ruta nueva `/change-expired-password`. Esa ruta usa el `ChangeUserPasswordRequest` existente a través del endpoint `/v1/users/{guid}/change-password` ya existente. No crear un endpoint nuevo para este caso.
Alternativa descartada: no guardar el token y crear un endpoint sin auth. Se descarta porque `change-password` requiere `auth:sanctum` y el usuario necesita estar autenticado para cambiar su propio password — que es exactamente lo que el input especifica.

**DEC-09 — Bug en updatePassword: orden de hash vs historial**
Decisión (corrección de bug existente): el código actual hace `$user->password = Hash::make($password)` y luego llama `$this->storePasswordHistory($user, $user->password)`. El cast `'hashed'` en el modelo no está activo en el assign directo (se aplica al persistir), pero igual es confuso. La nueva implementación guarda el hash en una variable local, lo asigna al modelo Y lo pasa explícitamente a `storePasswordHistory`. Se elimina cualquier ambigüedad sobre qué hash se guarda en el historial.

**DEC-10 — Qué hash guardar en el historial**
Decisión: guardar el hash del password NUEVO en `user_password_histories` después de la verificación exitosa. La verificación de "¿este password ya fue usado?" ocurre con `Hash::check($plainPassword, $history->password)` iterando los N registros más recientes. El hash del password actual del usuario (antes del cambio) también debe guardarse en la primera entrada cuando se crea el historial por primera vez (migración/registro inicial no está en alcance — se guarda al primer cambio).
Justificación: guardar el hash nuevo es lo correcto porque al siguiente cambio, el nuevo password ingresado se comparará contra el histórico con `Hash::check()`.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/database/migrations/2026_05_30_000001_create_system_settings_table.php`
**Propósito:** Crear tabla EAV global de configuraciones del sistema.
```php
Schema::create('system_settings', function (Blueprint $table) {
    $table->id();
    $table->string('code', 100)->unique();
    $table->text('value');
    $table->string('type', 20)->default('string'); // string|integer|boolean|json
    $table->text('description')->nullable();
    $table->timestamps();
});
```
Reversible: `Schema::dropIfExists('system_settings')`.
Sin foreign keys (tabla standalone). Sin guid (ver DEC-06).

#### `back/database/migrations/2026_05_30_000002_create_user_password_histories_table.php`
**Propósito:** Guardar hashes de passwords anteriores por usuario.
```php
Schema::create('user_password_histories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')
        ->constrained('users')
        ->cascadeOnDelete();
    $table->string('password'); // bcrypt hash
    $table->timestamp('created_at')->useCurrent();
    $table->index('user_id');
});
```
Reversible: `Schema::dropIfExists('user_password_histories')`.
Sin `updated_at` (tabla append-only). Sin guid (tabla interna, nunca expuesta por API — ver DEC-06).

#### `back/app/Models/SystemSetting.php`
**Propósito:** Modelo Eloquent para configuraciones globales.
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['code', 'value', 'type', 'description'];

    public function getParsedValueAttribute(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }
}
```
No implementa `HasGuid` (ver DEC-06). No tiene relaciones.

#### `back/app/Models/UserPasswordHistory.php`
**Propósito:** Modelo Eloquent para historial de passwords.
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasswordHistory extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = ['user_id', 'password'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```
No tiene `updated_at`. No implementa `HasGuid` (tabla interna, nunca expuesta).

#### `back/app/Contracts/Repositories/SystemSettingRepositoryInterface.php`
**Propósito:** Contrato del repositorio de configuraciones globales.
```php
namespace App\Contracts\Repositories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;

interface SystemSettingRepositoryInterface
{
    public function all(): Collection;
    public function findByCode(string $code): ?SystemSetting;
    public function getValue(string $code, mixed $default = null): mixed;
    public function upsert(string $code, string $value): SystemSetting;
}
```

#### `back/app/Repositories/SystemSettingRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de configuraciones globales.
```php
namespace App\Repositories;

use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;

class SystemSettingRepositoryEloquent implements SystemSettingRepositoryInterface
{
    public function all(): Collection
    {
        return SystemSetting::orderBy('code')->get();
    }

    public function findByCode(string $code): ?SystemSetting
    {
        return SystemSetting::where('code', $code)->first();
    }

    public function getValue(string $code, mixed $default = null): mixed
    {
        $setting = $this->findByCode($code);
        return $setting ? $setting->parsed_value : $default;
    }

    public function upsert(string $code, string $value): SystemSetting
    {
        // No modifica type ni description — esos solo cambia el seeder/migraciones
        $setting = SystemSetting::where('code', $code)->firstOrFail();
        $setting->value = $value;
        $setting->save();
        return $setting;
    }
}
```
**Dependencias inyectadas:** ninguna (acceso directo al Model, no hay interface de BaseRepository que aplique).

#### `back/app/Services/SystemSettingService.php`
**Propósito:** Lógica de negocio para el CRUD de configuraciones globales.
```php
namespace App\Services;

use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SystemSettingService
{
    public function __construct(
        private SystemSettingRepositoryInterface $repository,
    ) {}

    public function list(): Collection
    {
        return $this->repository->all();
    }

    public function findByCode(string $code): ?SystemSetting
    {
        return $this->repository->findByCode($code);
    }

    public function update(string $code, string $value): SystemSetting
    {
        $setting = $this->repository->findByCode($code);

        if (! $setting) {
            throw ValidationException::withMessages(['code' => ['Configuración no encontrada.']]);
        }

        // Validar que el valor sea coherente con el type
        $this->validateValue($setting->type, $value);

        return $this->repository->upsert($code, $value);
    }

    private function validateValue(string $type, string $value): void
    {
        match ($type) {
            'integer' => is_numeric($value)
                ? null
                : throw ValidationException::withMessages(['value' => ['El valor debe ser un número entero.']]),
            'boolean' => in_array(strtolower($value), ['true', 'false', '1', '0'], true)
                ? null
                : throw ValidationException::withMessages(['value' => ['El valor debe ser verdadero o falso.']]),
            default => null,
        };
    }
}
```
**Dependencias inyectadas:** `SystemSettingRepositoryInterface`.

#### `back/app/Http/Requests/SystemSettings/UpdateSystemSettingRequest.php`
**Propósito:** Validación del request de actualización de un setting global.
```php
namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'value.required' => 'El valor es requerido.',
            'value.max'      => 'El valor no puede superar los 1000 caracteres.',
        ];
    }
}
```

#### `back/app/Http/Resources/V1/SystemSettingResource.php`
**Propósito:** Serialización de un SystemSetting para la API.
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'         => $this->code,
            'value'        => $this->parsed_value,
            'type'         => $this->type,
            'description'  => $this->description,
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
```
No expone `id`. No hay guid (ver DEC-06).

#### `back/app/Http/Controllers/V1/SystemSettingController.php`
**Propósito:** Controlador REST para el CRUD de configuraciones globales.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemSettings\UpdateSystemSettingRequest;
use App\Http\Resources\V1\SystemSettingResource;
use App\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;

class SystemSettingController extends Controller
{
    public function __construct(
        private SystemSettingService $systemSettingService,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $settings = $this->systemSettingService->list();
            return $this->makeSuccess(SystemSettingResource::collection($settings));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $code): JsonResponse
    {
        try {
            $setting = $this->systemSettingService->findByCode($code);
            if (! $setting) {
                return $this->makeNotFound('Configuración no encontrada.');
            }
            return $this->makeSuccess(new SystemSettingResource($setting));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateSystemSettingRequest $request, string $code): JsonResponse
    {
        try {
            $setting = $this->systemSettingService->update($code, $request->validated()['value']);
            return $this->makeSuccess(new SystemSettingResource($setting), 'Configuración actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```
Parámetro de ruta `$code` (string legible), no guid (ver DEC-06).

#### `back/routes/api/system-settings.php`
**Propósito:** Rutas del módulo SystemSettings.
```php
use App\Http\Controllers\V1\SystemSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/system-settings')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/', [SystemSettingController::class, 'index']);
        Route::get('/{code}', [SystemSettingController::class, 'show']);
        Route::patch('/{code}', [SystemSettingController::class, 'update']);
    });
```
El permiso `system-settings.manage` se verificará dentro del controlador con `$this->authorize()` o se puede aplicar como middleware `can:system-settings.manage` en las rutas de escritura. Decisión: aplicar como middleware en la ruta `PATCH` para mantener el patrón delgado del controlador. Las rutas GET también requieren el permiso (solo admins ven esto).

Versión final de las rutas:
```php
Route::prefix('v1/system-settings')
    ->middleware(['auth:sanctum', 'can:system-settings.manage'])
    ->group(function () {
        Route::get('/', [SystemSettingController::class, 'index']);
        Route::get('/{code}', [SystemSettingController::class, 'show']);
        Route::patch('/{code}', [SystemSettingController::class, 'update']);
    });
```

#### `back/database/seeders/SystemSettingSeeder.php`
**Propósito:** Seedear las configuraciones globales por defecto.
```php
namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'code'        => 'password_expiration_months',
                'value'       => '3',
                'type'        => 'integer',
                'description' => 'Meses de vigencia del password. 0 = deshabilitado.',
            ],
            [
                'code'        => 'password_history_count',
                'value'       => '5',
                'type'        => 'integer',
                'description' => 'Cantidad de passwords anteriores que no se pueden reutilizar. 0 = deshabilitado.',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(
                ['code' => $setting['code']],
                [
                    'value'       => $setting['value'],
                    'type'        => $setting['type'],
                    'description' => $setting['description'],
                ],
            );
        }
    }
}
```
Usar `firstOrCreate` para que sea idempotente (no pisa valores modificados en prod).

---

### Archivos a modificar

#### `back/app/Contracts/Repositories/UserRepositoryInterface.php`
**Cambio:** Ningún cambio en la firma de `updatePassword`. El historial se maneja internamente en la implementación Eloquent. No se necesita exponer métodos de historial en la interfaz.

#### `back/app/Repositories/UserRepositoryEloquent.php`
**Cambio 1:** Inyectar `SystemSettingRepositoryInterface` en el constructor (no extiende BaseRepositoryEloquent con DI automático — el BaseRepo llama `app($this->model())`; el repositorio usa el constructor de la clase padre que no recibe parámetros). Solución: resolver el SystemSettingRepository dentro del método con `app()` o inyectarlo vía setter después de la construcción.

Decisión: usar `app(SystemSettingRepositoryInterface::class)` dentro del método `updatePassword` para no romper la cadena de herencia de `BaseRepositoryEloquent`. Este es un caso aceptable de `app()` en un repositorio porque el `BaseRepositoryEloquent::__construct` ya usa `app()` para instanciar el modelo y no hay un constructor público que permita DI limpia sin refactorizar la base.

**Cambio 2:** Reemplazar el placeholder `storePasswordHistory` con la implementación real. Corregir el bug del hash (ver DEC-09).

**Antes (resumido):**
```php
public function updatePassword(User $user, string $password): User
{
    $user->password = Hash::make($password);
    $user->failed_login_attempts = 0;
    $user->locked_at = null;
    $user->password_changed_at = now();
    $user->save();
    $this->storePasswordHistory($user, $user->password); // BUG: $user->password aquí ya es el hash nuevo
    return $user;
}

private function storePasswordHistory(User $user, string $hashedPassword, int $limit = 5): void
{
    // Placeholder
}
```

**Después:**
```php
public function updatePassword(User $user, string $password): User
{
    // 1. Verificar historial ANTES de modificar el usuario
    $this->checkPasswordHistory($user, $password);

    // 2. Hashear el password nuevo
    $hashedPassword = Hash::make($password);

    // 3. Persistir el cambio en el usuario
    $user->password               = $hashedPassword;
    $user->failed_login_attempts  = 0;
    $user->locked_at              = null;
    $user->password_changed_at    = now();
    $user->save();

    // 4. Guardar en historial usando el hash ya generado
    $this->storePasswordHistory($user, $hashedPassword);

    return $user;
}

private function checkPasswordHistory(User $user, string $plainPassword): void
{
    /** @var SystemSettingRepositoryInterface $settingRepo */
    $settingRepo = app(\App\Contracts\Repositories\SystemSettingRepositoryInterface::class);
    $limit = (int) $settingRepo->getValue('password_history_count', 0);

    if ($limit <= 0) {
        return; // Historial deshabilitado
    }

    $recentHashes = \App\Models\UserPasswordHistory::where('user_id', $user->id)
        ->orderByDesc('created_at')
        ->limit($limit)
        ->pluck('password');

    foreach ($recentHashes as $hash) {
        if (Hash::check($plainPassword, $hash)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password' => ["No podés reutilizar ninguno de los últimos {$limit} passwords."],
            ]);
        }
    }
}

private function storePasswordHistory(User $user, string $hashedPassword): void
{
    /** @var SystemSettingRepositoryInterface $settingRepo */
    $settingRepo = app(\App\Contracts\Repositories\SystemSettingRepositoryInterface::class);
    $limit = (int) $settingRepo->getValue('password_history_count', 0);

    if ($limit <= 0) {
        return; // Historial deshabilitado — no guardar
    }

    \App\Models\UserPasswordHistory::create([
        'user_id'  => $user->id,
        'password' => $hashedPassword,
    ]);

    // Purgar entradas que excedan el límite (conservar las N más recientes)
    $idsToKeep = \App\Models\UserPasswordHistory::where('user_id', $user->id)
        ->orderByDesc('created_at')
        ->limit($limit)
        ->pluck('id');

    \App\Models\UserPasswordHistory::where('user_id', $user->id)
        ->whereNotIn('id', $idsToKeep)
        ->delete();
}
```

**Imports a agregar al tope del archivo:**
```php
use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Models\UserPasswordHistory;
use Illuminate\Validation\ValidationException;
```

#### `back/app/Services/AuthService.php`
**Cambio:** Inyectar `SystemSettingRepositoryInterface` en el constructor y agregar la verificación de expiración en `login()`.

**Constructor — antes:**
```php
public function __construct(
    private UserRepositoryEloquent $userRepository,
) {}
```

**Constructor — después:**
```php
public function __construct(
    private UserRepositoryEloquent $userRepository,
    private SystemSettingRepositoryInterface $systemSettingRepository,
) {}
```

Nota: `AuthService` inyecta `UserRepositoryEloquent` concreto en lugar de la interface. No se cambia esta inconsistencia en este plan (es deuda técnica preexistente — ver Riesgos).

**Método `login()` — después (bloque a agregar después del `$user->save()`):**
```php
public function login(User $user): array
{
    if (! $user->email_verified_at) {
        return [
            'must_verify_account' => true,
            'user'                => $this->formatUser($user),
        ];
    }

    // Revocar tokens anteriores
    $user->tokens()->delete();

    $token = $user->createToken('api-access', ['*']);

    // Actualizar último login y resetear intentos fallidos
    $user->last_login_at         = now();
    $user->failed_login_attempts = 0;
    $user->locked_at             = null;
    $user->save();

    // Verificar expiración de password
    $mustChangePassword = $this->isPasswordExpired($user);

    return [
        'access_token'        => $token->plainTextToken,
        'user'                => $this->formatUser($user),
        'must_verify_account' => false,
        'must_change_password' => $mustChangePassword,
    ];
}

private function isPasswordExpired(User $user): bool
{
    $months = (int) $this->systemSettingRepository->getValue('password_expiration_months', 0);

    if ($months <= 0) {
        return false; // Expiración deshabilitada
    }

    if ($user->password_changed_at === null) {
        // Si nunca se cambió el password, usar created_at como referencia
        return now()->isAfter($user->created_at->addMonths($months));
    }

    return now()->isAfter($user->password_changed_at->addMonths($months));
}
```

**Import a agregar:**
```php
use App\Contracts\Repositories\SystemSettingRepositoryInterface;
```

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar binding de `SystemSettingRepositoryInterface`.

**Antes (extracto):**
```php
$this->app->bind(NotificationRepositoryInterface::class, NotificationRepositoryEloquent::class);
```

**Después:**
```php
$this->app->bind(NotificationRepositoryInterface::class, NotificationRepositoryEloquent::class);
$this->app->bind(SystemSettingRepositoryInterface::class, SystemSettingRepositoryEloquent::class);
```

**Imports a agregar:**
```php
use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Repositories\SystemSettingRepositoryEloquent;
```

#### `back/database/seeders/PermissionSeeder.php`
**Cambio:** Agregar el permiso `system-settings.manage` al array.

**Antes (extracto):**
```php
$permissions = [
    // ... permisos existentes
    'support-messages.close',
];
```

**Después:**
```php
$permissions = [
    // ... permisos existentes
    'support-messages.close',
    'system-settings.manage',
];
```

#### `back/database/seeders/RoleSeeder.php`
**Cambio:** Asignar el permiso `system-settings.manage` solo al rol `super-admin`.

El rol `super-admin` ya recibe todos los permisos via `$superAdmin->syncPermissions(Permission::all())`, así que no requiere cambio explícito. Solo verificar que el PermissionSeeder corra antes (ya es el caso en DatabaseSeeder).

**No modificar** los permisos de `admin` ni `operador` — este permiso es solo para `super-admin`.

#### `back/database/seeders/DatabaseSeeder.php`
**Cambio:** Agregar `SystemSettingSeeder` al array de seeders.

**Antes:**
```php
$this->call([
    PermissionSeeder::class,
    RoleSeeder::class,
]);
```

**Después:**
```php
$this->call([
    PermissionSeeder::class,
    RoleSeeder::class,
    SystemSettingSeeder::class,
]);
```

---

### Migrations

| # | Archivo | Tabla | Acción |
|---|---------|-------|--------|
| 1 | `2026_05_30_000001_create_system_settings_table.php` | `system_settings` | CREATE |
| 2 | `2026_05_30_000002_create_user_password_histories_table.php` | `user_password_histories` | CREATE |

Ambas reversibles con `dropIfExists`.

---

### Rutas API

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| GET | `/api/v1/system-settings` | `SystemSettingController@index` | `auth:sanctum`, `can:system-settings.manage` |
| GET | `/api/v1/system-settings/{code}` | `SystemSettingController@show` | `auth:sanctum`, `can:system-settings.manage` |
| PATCH | `/api/v1/system-settings/{code}` | `SystemSettingController@update` | `auth:sanctum`, `can:system-settings.manage` |

Las rutas existentes de users y auth no se modifican (la nueva lógica está en Services/Repositories).

---

### Permisos Spatie

| Permiso | Seeder | Roles que lo reciben |
|---------|--------|----------------------|
| `system-settings.manage` | `PermissionSeeder` | `super-admin` (recibe todos via `Permission::all()`) |

---

### Contrato de endpoints

#### GET /api/v1/system-settings
Response 200:
```json
{
  "success": true,
  "data": [
    {
      "code": "password_expiration_months",
      "value": 3,
      "type": "integer",
      "description": "Meses de vigencia del password. 0 = deshabilitado.",
      "updated_at": "2026-05-30T00:00:00.000000Z"
    },
    {
      "code": "password_history_count",
      "value": 5,
      "type": "integer",
      "description": "Cantidad de passwords anteriores que no se pueden reutilizar. 0 = deshabilitado.",
      "updated_at": "2026-05-30T00:00:00.000000Z"
    }
  ]
}
```

#### PATCH /api/v1/system-settings/{code}
Request:
```json
{
  "value": "6"
}
```
Response 200:
```json
{
  "success": true,
  "data": {
    "code": "password_expiration_months",
    "value": 6,
    "type": "integer",
    "description": "Meses de vigencia del password. 0 = deshabilitado.",
    "updated_at": "2026-05-30T12:00:00.000000Z"
  },
  "message": "Configuración actualizada correctamente."
}
```
Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 401 | Sin token |
| 403 | Sin permiso `system-settings.manage` |
| 404 | Code no encontrado |
| 422 | Value no es del tipo esperado |

#### POST /api/v1/auth/login — response modificado
```json
{
  "success": true,
  "data": {
    "access_token": "token...",
    "user": { "...": "..." },
    "must_verify_account": false,
    "must_change_password": true
  }
}
```
Cuando `must_change_password` es `true`, el cliente recibe un token válido pero DEBE redirigir al flujo de cambio de password.

#### PATCH /api/v1/users/{guid}/change-password — response de error por historial
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "password": ["No podés reutilizar ninguno de los últimos 5 passwords."]
  }
}
```
HTTP 422. El frontend ya maneja este formato via `parseApiError` en `useChangePassword`.

---

### Tests a generar (qué cubrir)

**Feature tests — SystemSettingController:**
- `GET /v1/system-settings` sin auth → 401.
- `GET /v1/system-settings` con auth pero sin permiso → 403.
- `GET /v1/system-settings` con superadmin → 200 + lista de 2 settings.
- `PATCH /v1/system-settings/password_expiration_months` valor válido → 200 + valor actualizado.
- `PATCH /v1/system-settings/password_expiration_months` valor no numérico → 422.
- `PATCH /v1/system-settings/codigo_inexistente` → 404.

**Feature tests — Login con expiración:**
- Login con `password_expiration_months = 0` → `must_change_password: false`.
- Login con `password_expiration_months = 3` y `password_changed_at` hace 2 meses → `must_change_password: false`.
- Login con `password_expiration_months = 3` y `password_changed_at` hace 4 meses → `must_change_password: true`.
- Login con `password_expiration_months = 3` y `password_changed_at = null` y usuario creado hace 4 meses → `must_change_password: true`.
- Login con `must_change_password: true` → token presente en la respuesta (usuario puede cambiar password).

**Feature tests — changePassword con historial:**
- Cambiar a un password nuevo (no en historial) con `password_history_count = 5` → 200, entrada guardada en `user_password_histories`.
- Intentar reutilizar el último password → 422 con mensaje de historial.
- Intentar reutilizar el 4to password anterior (dentro del límite) → 422.
- Intentar reutilizar el 6to password anterior (fuera del límite de 5) → 200 (permitido).
- `password_history_count = 0` → cambio permitido siempre, tabla vacía.
- Verificar que después del cambio la tabla tiene máximo `password_history_count` entradas.

**Feature tests — forgotPassword con historial:**
- `resetPassword` intentando reutilizar password en historial → 422.
- `resetPassword` con password nuevo → 200.

**Feature tests — resetPassword admin con historial:**
- `POST /v1/users/{guid}/reset-password` (admin reset) genera password aleatorio que no está en historial → 200.
  (Nota: este caso es casi imposible de fallar dado que el password es aleatorio de 10 chars, pero la verificación igual corre).

**Unit tests — UserRepositoryEloquent:**
- `checkPasswordHistory`: verifica que lanza ValidationException cuando el plain password coincide con un hash en historial.
- `storePasswordHistory`: verifica que purga entradas antiguas y conserva exactamente `limit` entradas.

**Unit tests — AuthService:**
- `isPasswordExpired` (método privado — testear via `login()`): los 4 casos del feature test de login.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/system-settings/types/system-settings.types.ts`
```typescript
export type SettingType = 'string' | 'integer' | 'boolean' | 'json'

export interface SystemSetting {
    code: string
    value: string | number | boolean
    type: SettingType
    description: string | null
    updated_at: string | null
}

export interface UpdateSystemSettingPayload {
    value: string
}
```

#### `front/src/modules/system-settings/api/system-settings.api.ts`
```typescript
import { http } from '@/core/api/http'
import type { SystemSetting, UpdateSystemSettingPayload } from '../types/system-settings.types'

export async function listSystemSettingsApi(): Promise<SystemSetting[]> {
    const response = await http.get<SystemSetting[]>('/v1/system-settings')
    return response.data
}

export async function getSystemSettingApi(code: string): Promise<SystemSetting> {
    const response = await http.get<SystemSetting>(`/v1/system-settings/${code}`)
    return response.data
}

export async function updateSystemSettingApi(
    code: string,
    payload: UpdateSystemSettingPayload,
): Promise<SystemSetting> {
    const response = await http.patch<SystemSetting>(`/v1/system-settings/${code}`, payload)
    return response.data
}
```

#### `front/src/modules/system-settings/composables/useSystemSettings.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { listSystemSettingsApi } from '../api/system-settings.api'

export function useSystemSettings() {
    return useQuery({
        queryKey: ['system-settings'],
        queryFn: listSystemSettingsApi,
    })
}
```

#### `front/src/modules/system-settings/composables/useUpdateSystemSetting.ts`
```typescript
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateSystemSettingApi } from '../api/system-settings.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import { ref } from 'vue'
import type { UpdateSystemSettingPayload } from '../types/system-settings.types'

export function useUpdateSystemSetting() {
    const queryClient = useQueryClient()
    const { success, error } = useNotification()
    const fieldErrors = ref<Record<string, string> | null>(null)
    const generalError = ref<string | null>(null)

    const mutation = useMutation({
        mutationFn: ({ code, payload }: { code: string; payload: UpdateSystemSettingPayload }) =>
            updateSystemSettingApi(code, payload),
        onMutate: () => {
            fieldErrors.value = null
            generalError.value = null
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['system-settings'] })
            success('Configuración actualizada correctamente.')
        },
        onError: (err: unknown) => {
            const apiError = parseApiError(err)
            fieldErrors.value = apiError.fieldErrors
            generalError.value = apiError.message ?? 'Error al actualizar la configuración.'
            if (apiError.message) error('Error al actualizar la configuración.')
        },
    })

    return { ...mutation, fieldErrors, generalError }
}
```

#### `front/src/modules/system-settings/pages/SystemSettingsPage.vue`
**Propósito:** Página admin para listar y editar configuraciones globales.
Estructura: tabla con columnas Code, Descripción, Valor, Tipo, Última actualización + botón Editar que abre un modal con un input de texto y validación. Reutiliza el patrón de `SettingsPage.vue` del módulo de user-settings.

Esqueleto:
```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useSystemSettings } from '../composables/useSystemSettings'
import { useUpdateSystemSetting } from '../composables/useUpdateSystemSetting'
import AppHeader from '@/components/layouts/partials/AppHeader.vue'

const { data: settings, isLoading } = useSystemSettings()
const { mutate: updateSetting, fieldErrors, generalError } = useUpdateSystemSetting()

const editingCode = ref<string | null>(null)
const editingValue = ref<string>('')

function openEdit(code: string, currentValue: string | number | boolean) {
    editingCode.value = code
    editingValue.value = String(currentValue)
}

function closeEdit() {
    editingCode.value = null
    editingValue.value = ''
}

function confirmEdit() {
    if (!editingCode.value) return
    updateSetting(
        { code: editingCode.value, payload: { value: editingValue.value } },
        { onSuccess: () => closeEdit() },
    )
}
</script>
```

#### `front/src/modules/system-settings/router/system-settings.routes.ts`
```typescript
import type { RouteRecordRaw } from 'vue-router'

export const systemSettingsRoutes: RouteRecordRaw[] = [
    {
        path: '/admin/system-settings',
        name: 'system-settings',
        component: () => import('@/modules/system-settings/pages/SystemSettingsPage.vue'),
        meta: { requiresAuth: true, title: 'Configuración del Sistema' },
    },
]
```

#### `front/src/modules/auth/pages/ChangeExpiredPasswordPage.vue`
**Propósito:** Página que se muestra cuando `must_change_password: true` en el login. Presenta el formulario de cambio de password usando el endpoint existente `PATCH /v1/users/{guid}/change-password`.

Esqueleto:
```vue
<script setup lang="ts">
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { changePasswordSchema } from '@/modules/users/validators/user.validator'
import { changePasswordUserApi } from '@/modules/users/api/users.api'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { useRouter } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { ref } from 'vue'

const authStore = useAuthStore()
const router = useRouter()
const { success, error } = useNotification()
const serverError = ref<string | null>(null)

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(changePasswordSchema),
})

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null
    try {
        await changePasswordUserApi(authStore.user!.guid, {
            password: values.password,
            password_confirmation: values.password_confirmation,
        })
        success('Contraseña actualizada correctamente.')
        router.push('/dashboard')
    } catch (err: unknown) {
        const e = err as { message?: string }
        serverError.value = e.message ?? 'Error al cambiar la contraseña.'
        error(serverError.value)
    }
})
</script>
```

Nota: esta página requiere que `authStore.user` y `authStore.token` ya estén seteados (el login los setea antes de redirigir aquí). El `authGuard` protege la ruta.

---

### Archivos a modificar

#### `front/src/modules/auth/stores/auth.store.ts`
**Cambio:** En el método `login()`, manejar el caso `must_change_password: true`.

**Antes:**
```typescript
interface AuthStoreState {
    token: string | null
    user: User | null
    isLoggingIn: boolean
    isLoggingOut: boolean
    mustVerifyAccount: boolean
    pendingVerificationGuid: string | null
}
```

**Después:** agregar campo `mustChangePassword`:
```typescript
interface AuthStoreState {
    token: string | null
    user: User | null
    isLoggingIn: boolean
    isLoggingOut: boolean
    mustVerifyAccount: boolean
    pendingVerificationGuid: string | null
    mustChangePassword: boolean      // NUEVO
}
```

En `state()`, inicializar `mustChangePassword: false`.

En el método `login()`, bloque después de `this.token = data.access_token`:
```typescript
// Después de setear token y user:
this.mustChangePassword = data.must_change_password ?? false

// El return también incluye el nuevo campo:
return { must_verify_account: false, user: data.user as User, must_change_password: this.mustChangePassword }
```

El tipo de retorno del método `login()` también se actualiza:
```typescript
async login(email: string, password: string, remember = false): Promise<{
    must_verify_account?: boolean
    must_change_password?: boolean   // NUEVO
    user?: User
}>
```

#### `front/src/modules/auth/types/auth.types.ts`
**Cambio:** Agregar `must_change_password` a la interfaz `AuthState` y actualizar `LoginResponse` en el api file.

```typescript
export interface AuthState {
    token: string | null;
    user: User | null;
    isAuthenticated: boolean;
    mustVerifyAccount: boolean;
    mustChangePassword: boolean;     // NUEVO
}
```

#### `front/src/modules/auth/api/auth.api.ts`
**Cambio:** Agregar `must_change_password` a `LoginResponse`.

**Antes:**
```typescript
export interface LoginResponse {
    access_token: string;
    user: UserApiResponse;
    must_verify_account: boolean;
}
```

**Después:**
```typescript
export interface LoginResponse {
    access_token: string;
    user: UserApiResponse;
    must_verify_account: boolean;
    must_change_password: boolean;   // NUEVO
}
```

#### `front/src/modules/auth/composables/useLogin.ts`
**Cambio:** Manejar la redirección a `/change-expired-password` cuando `must_change_password` es `true`.

**Antes:**
```typescript
const result = await authStore.login(values.email, values.password, values.remember ?? false)
if (result.must_verify_account && result.user) {
    router.push(`/verify-account/${result.user.guid}`)
    return
}
router.push('/dashboard')
```

**Después:**
```typescript
const result = await authStore.login(values.email, values.password, values.remember ?? false)
if (result.must_verify_account && result.user) {
    router.push(`/verify-account/${result.user.guid}`)
    return
}
if (result.must_change_password) {
    router.push('/change-expired-password')
    return
}
router.push('/dashboard')
```

Aplicar el mismo cambio en `LoginPage.vue` que tiene el mismo flujo de login inline.

#### `front/src/modules/auth/router/auth.routes.ts`
**Cambio:** Agregar la ruta de cambio de password expirado.

```typescript
{
    path: '/change-expired-password',
    name: 'change-expired-password',
    component: () => import('@/modules/auth/pages/ChangeExpiredPasswordPage.vue'),
    meta: { requiresAuth: true },   // Requiere estar autenticado (token ya seteado)
},
```
Esta ruta va dentro del grupo con `AppLayout` (no `AuthLayout`) porque el usuario ya está logueado.

#### `front/src/router/index.ts`
**Cambio 1:** Importar y agregar `systemSettingsRoutes` a las rutas del app.

```typescript
import { systemSettingsRoutes } from '@/modules/system-settings/router/system-settings.routes'

// En el array children del path '/':
children: [
    ...dashboardRoutes,
    ...usersRoutes,
    ...rolesRoutes,
    ...settingsRoutes,
    ...supportMessagesRoutes,
    ...systemSettingsRoutes,       // NUEVO
],
```

**Cambio 2:** Agregar la ruta `change-expired-password` al grupo raíz (con `AppLayout` y `authGuard`).

```typescript
// Dentro del children del path '/':
{
    path: 'change-expired-password',
    name: 'change-expired-password',
    component: () => import('@/modules/auth/pages/ChangeExpiredPasswordPage.vue'),
    meta: { requiresAuth: true },
},
```

Nota: la ruta `change-expired-password` no debe ir en `authRoutes` (que usa `AuthLayout`) sino en el grupo raíz que usa `AppLayout`, porque el usuario ya está autenticado y debería ver el layout del dashboard (o como alternativa, un layout minimalista). Si el equipo prefiere un layout distinto, se puede crear un `ForceChangePasswordLayout`, pero para esta iteración se usa `AppLayout`.

#### `front/src/modules/auth/pages/LoginPage.vue`
**Cambio:** Agregar el manejo de `must_change_password` en el `handleSubmit` inline de la página (líneas 32-44 del archivo actual).

```typescript
if (result.must_change_password) {
    router.push('/change-expired-password');
    return;
}
```
Insertar antes de `router.push('/dashboard')`.

---

## Orden de implementación

1. Crear migration `2026_05_30_000001_create_system_settings_table.php` y correrla.
2. Crear migration `2026_05_30_000002_create_user_password_histories_table.php` y correrla.
3. Crear model `SystemSetting` en `back/app/Models/SystemSetting.php`.
4. Crear model `UserPasswordHistory` en `back/app/Models/UserPasswordHistory.php`.
5. Crear `back/database/seeders/SystemSettingSeeder.php`.
6. Agregar `system-settings.manage` a `PermissionSeeder`, agregar `SystemSettingSeeder` a `DatabaseSeeder` y correr `php artisan db:seed`.
7. Crear interface `back/app/Contracts/Repositories/SystemSettingRepositoryInterface.php`.
8. Crear repositorio `back/app/Repositories/SystemSettingRepositoryEloquent.php`.
9. Agregar binding en `AppServiceProvider`.
10. Crear servicio `back/app/Services/SystemSettingService.php`.
11. Crear FormRequest `back/app/Http/Requests/SystemSettings/UpdateSystemSettingRequest.php`.
12. Crear resource `back/app/Http/Resources/V1/SystemSettingResource.php`.
13. Crear controller `back/app/Http/Controllers/V1/SystemSettingController.php`.
14. Crear archivo de rutas `back/routes/api/system-settings.php` (se carga automáticamente por el glob en `routes/api.php`).
15. Modificar `back/app/Repositories/UserRepositoryEloquent.php`: reemplazar `storePasswordHistory` placeholder con la implementación real, agregar `checkPasswordHistory`, corregir bug del hash (DEC-09), agregar imports.
16. Modificar `back/app/Services/AuthService.php`: inyectar `SystemSettingRepositoryInterface`, agregar método privado `isPasswordExpired`, modificar `login()` para devolver `must_change_password`.
17. Correr feature tests de backend para SystemSettings, login y changePassword.
18. (Frontend) Crear `front/src/modules/system-settings/types/system-settings.types.ts`.
19. (Frontend) Crear `front/src/modules/system-settings/api/system-settings.api.ts`.
20. (Frontend) Crear composables `useSystemSettings` y `useUpdateSystemSetting`.
21. (Frontend) Crear página `SystemSettingsPage.vue` y rutas `system-settings.routes.ts`.
22. (Frontend) Modificar `auth.api.ts`: agregar `must_change_password` a `LoginResponse`.
23. (Frontend) Modificar `auth.types.ts`: agregar `mustChangePassword` a `AuthState`.
24. (Frontend) Modificar `auth.store.ts`: agregar campo `mustChangePassword` al state y manejar en `login()`.
25. (Frontend) Crear página `ChangeExpiredPasswordPage.vue`.
26. (Frontend) Modificar `auth.routes.ts`: agregar ruta `change-expired-password` (como referencia — la ruta efectiva va en el router principal).
27. (Frontend) Modificar `router/index.ts`: agregar `systemSettingsRoutes` y la ruta `change-expired-password` en el grupo raíz.
28. (Frontend) Modificar `useLogin.ts` y `LoginPage.vue`: manejar redirección a `/change-expired-password`.
29. Correr la aplicación y verificar manualmente el flujo completo: login con password expirado → redirección → cambio de password → redirección a dashboard.

---

## Riesgos y consideraciones

**R-01 — Bug preexistente en `updatePassword` (corregido en este plan)**
`$user->password = Hash::make($password)` y luego `$this->storePasswordHistory($user, $user->password)`. En ese punto el cast `'hashed'` del modelo ya almacenó el valor crudo de la asignación (que es el hash de `Hash::make`), no el texto plano — así que el comportamiento no era incorrecto funcionalmente, pero era confuso y dependía del comportamiento del cast. El plan lo clarifica explícitamente con una variable local `$hashedPassword`.

**R-02 — `AuthService` inyecta `UserRepositoryEloquent` concreto en lugar de la interface**
El constructor de `AuthService` usa `UserRepositoryEloquent` directamente, no `UserRepositoryInterface`. Esto es deuda técnica preexistente. Este plan la propaga (agrega un segundo parámetro concreto `SystemSettingRepositoryInterface` que sí usa la interface). No se corrige en esta iteración para no ampliar el alcance del cambio, pero está registrado.

**R-03 — `app()` dentro del método del repositorio**
`checkPasswordHistory` y `storePasswordHistory` usan `app(SystemSettingRepositoryInterface::class)` porque `BaseRepositoryEloquent::__construct` no acepta parámetros de DI. Esto es un antipatrón menor (service locator dentro del repositorio). La alternativa limpia es refactorizar `BaseRepositoryEloquent` para permitir DI en subclases, pero está fuera del alcance de este plan. El riesgo real es bajo: el binding está en el AppServiceProvider y el test puede mockear el container.

**R-04 — N+1 en `checkPasswordHistory` si `password_history_count` es alto**
La verificación hace `Hash::check()` en un loop de hasta N hashes. Con N=5 (default) y bcrypt, son hasta 5 bcrypt checks por cambio de password. Esto es aceptable (cambio de password es infrecuente). Si en el futuro el límite fuera muy alto (ej. 20), habría que evaluar estrategias de optimización. No es un riesgo operativo en esta iteración.

**R-05 — `must_change_password` no bloquea otros endpoints**
El plan devuelve el token al usuario con `must_change_password: true` para que pueda hacer el cambio. El frontend es responsable de redirigir al flujo correcto. Si el usuario manipula el frontend (o usa la API directamente) puede acceder a otros endpoints sin cambiar el password. En esta iteración no se implementa un middleware que bloquee el acceso — eso es una mejora futura que requiere consenso de producto.

**R-06 — `ChangeExpiredPasswordPage.vue` usa el endpoint de admin `users/{guid}/change-password`**
Este endpoint (PATCH) acepta cualquier usuario autenticado con el guid correcto. No hay verificación de que el usuario solo pueda cambiar su propio password — eso debería validarse en el backend (el controller no lo verifica actualmente). En el contexto de password expirado, el usuario siempre va a usar su propio guid (viene del `authStore.user.guid`), pero técnicamente un usuario podría llamar el endpoint con el guid de otro usuario si tiene el token. Esto es un riesgo de seguridad preexistente (no introducido por este plan), que conviene corregir en la iteración de seguridad.

**R-07 — Purga de historial con `whereNotIn` en tablas grandes**
Si un usuario tiene muchas entradas en `user_password_histories` (por ejemplo por un bug previo), la purga con `whereNotIn` es eficiente para N pequeños (≤10). Para volúmenes grandes sería mejor un delete por subquery. Con un límite de 5-10 entradas el riesgo es nulo.

**R-08 — `password_changed_at = null` en usuarios existentes**
Usuarios creados antes de esta implementación pueden tener `password_changed_at = null`. El plan usa `created_at` como fallback para la verificación de expiración. Esto puede causar que usuarios muy viejos reciban `must_change_password: true` en su próximo login si el `created_at` supera N meses. Esto es el comportamiento correcto para un sistema de política de passwords, pero el equipo puede preferir una migration que setee `password_changed_at = now()` para todos los usuarios existentes antes de activar la feature en producción.

**R-09 — `SystemSettingSeeder` y entornos de producción**
`firstOrCreate` es idempotente, pero si en producción hay datos modificados manualmente, el seeder no los pisará. Las configuraciones globales son datos de negocio, no estructura — correr `db:seed --class=SystemSettingSeeder` en producción solo insertará los registros faltantes.

**R-10 — Permisos: `system-settings.manage` y middleware `can`**
El guard de Spatie usa `'web'`, no `'sanctum'`. El middleware `can:system-settings.manage` en Laravel verifica contra el guard por defecto del usuario autenticado. Con Sanctum + guard `'web'` para permisos, esto funciona correctamente (el usuario autenticado via Sanctum tiene sus roles/permisos Spatie disponibles). Verificar en los tests que la verificación de permiso funciona en el contexto de Sanctum.

---

## Pendientes / fuera de alcance

- **Middleware que bloquee endpoints cuando `must_change_password: true`**: requiere decisión de producto sobre qué operaciones debe poder hacer el usuario con password expirado. Se puede implementar en la siguiente iteración como un middleware `EnsurePasswordIsFresh`.
- **Cache en `SystemSettingRepository::getValue`**: agregar `Cache::remember('system_setting_' . $code, 3600, fn() => ...)` para reducir queries en endpoints de alta frecuencia (login). Fuera del alcance para no agregar complejidad antes de medir el impacto real.
- **Setear `password_changed_at = now()` para usuarios existentes en producción**: migration de datos opcional. El equipo debe decidir si activar la expiración de forma gradual o inmediata.
- **Refactorizar `AuthService` para usar `UserRepositoryInterface`**: deuda técnica R-02, fuera del alcance de este plan.
- **Verificar que el usuario solo pueda cambiar su propio password** (R-06): fix de seguridad en `UserController::changePassword`, fuera del alcance de este plan.
- **Historial para el reset de password admin (`UserService::resetPassword`)**: el password generado es aleatorio, así que la verificación siempre pasará. El historial SÍ se guarda correctamente (porque `updatePassword` lo hace internamente). No hay acción adicional requerida.
- **Notificación por email cuando el password está próximo a expirar**: feature de UX, requiere job/queue, fuera del alcance.
- **Internacionalización de mensajes de error del historial**: los mensajes están en castellano hardcodeados. Consistente con el resto del proyecto (no hay sistema i18n en el backend).
