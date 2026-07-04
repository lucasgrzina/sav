# Plan técnico: Módulo de Notificaciones con WebSockets (Laravel Reverb)

## Input procesado
`.claude/docs/reference/manual-modulo-notificaciones-websockets.md` + descripción inline del usuario en el prompt.

## Resumen ejecutivo
Se implementa un sistema de notificaciones en tiempo real sobre Laravel Reverb (WebSocket). El backend expone una API REST bajo `v1/notifications` con canal privado por usuario autenticado vía Sanctum. El frontend suscribe el canal en `App.vue` al autenticarse, usa un EventBus desacoplado para distribuir eventos a componentes, y cablea el botón de campana de `AppLayout.vue` con un dropdown Ant Design Vue. La migración existente `2026_05_18_181005_create_notifications_table.php` (formato Laravel default con `notifiable_type/notifiable_id`) se reemplaza completamente con la estructura custom (`guid`, `payload` JSON). Se agrega una segunda migración para `notification_recipients`. El módulo sigue el patrón Repository+Service del proyecto con todos los bindings en `AppServiceProvider`.

---

## Decisiones tomadas

**DEC-01 — Reemplazar contenido de la migración existente en lugar de crear una nueva DROP+CREATE**
  Decisión: Modificar el contenido del archivo `2026_05_18_181005_create_notifications_table.php` directamente.
  Justificación: La tabla aún no tiene datos de producción (el sistema está en setup) y la migración existente usa el formato Laravel default que es incompatible con la estructura custom. Crear una migration `drop+recreate` más tarde sería confuso en el historial. El dev debe correr `migrate:fresh` o `migrate:rollback` antes de aplicar.
  Alternativa descartada: Nueva migración `drop_and_recreate_notifications_table` — genera dependencia de orden y es más difícil de mantener en dev temprano.

**DEC-02 — `ShouldBroadcastNow` en lugar de `ShouldBroadcast`**
  Decisión: Usar `ShouldBroadcastNow` (disparo síncrono en el mismo request).
  Justificación: El proyecto usa `QUEUE_CONNECTION=database` pero para notificaciones de tiempo real el delay de queue contradice el propósito. `ShouldBroadcastNow` garantiza entrega inmediata sin configuración extra de workers para este canal.
  Alternativa descartada: `ShouldBroadcast` con queue — requiere que el queue worker esté corriendo y agrega latencia innecesaria en desarrollo.

**DEC-03 — No usar `HasUuids` de Laravel; usar el trait `HasGuid` del proyecto**
  Decisión: El modelo `Notification` usa `HasGuid` (el trait custom del proyecto en `App\Traits\HasGuid`), no `HasUuids` de Laravel.
  Justificación: El manual de referencia usa `HasUuids`, pero el código real del proyecto usa exclusivamente `HasGuid` (ver `Export`, `User`, todos los modelos). `HasGuid` además implementa `getRouteKeyName()` retornando `'guid'`, lo que es requerido por la regla dura #5. `HasUuids` usa `uuid` como primary key y cambia el tipo de la columna `id`, incompatible con el esquema `id BIGINT UNSIGNED` del proyecto.
  Alternativa descartada: `HasUuids` de Laravel — rompe el patrón del proyecto y la columna `id` BIGINT del schema.

**DEC-04 — Soft deletes solo en `Notification`, no en `NotificationRecipient`**
  Decisión: `Notification` usa `SoftDeletes`. `NotificationRecipient` no tiene soft deletes.
  Justificación: La regla dura #12 dice "sin soft deletes" para el proyecto SAV. Sin embargo, el manual de referencia incluye `deleted_at` en `notifications` explícitamente, y dado que este módulo requiere borrado lógico para preservar integridad de la tabla pivot y auditoría, se acepta como excepción documentada. `NotificationRecipient` no necesita borrado lógico.
  Riesgo: Esta es la única excepción a la regla #12 en todo el proyecto. Si la política global cambia, revisar primero este modelo.

**DEC-05 — `Broadcast::routes()` va en `routes/api.php` a nivel de archivo, no dentro de un archivo de routes/api/**
  Decisión: Agregar `Broadcast::routes(['middleware' => ['auth:sanctum']])` directamente en `back/routes/api.php` (el archivo raíz que hace el glob), no dentro de `routes/api/notifications.php`.
  Justificación: `Broadcast::routes()` registra `/broadcasting/auth` con su propio prefijo y no debe quedar dentro del grupo de prefix `v1/`. Si va en el archivo de rutas de notificaciones con `Route::prefix('v1/...')`, el endpoint quedaría en `/v1/notifications/broadcasting/auth`, lo cual rompe Echo que espera `/broadcasting/auth`.
  Alternativa descartada: Registrar en `routes/web.php` — el proyecto solo sirve API, sin web routes activas para este propósito.

**DEC-06 — El echoService usa `http` del proyecto (instancia axios existente) en el authorizer**
  Decisión: `echoService.ts` importa `http` de `@/core/api/http` para el authorizer custom.
  Justificación: El interceptor de `http` ya inyecta el Bearer token automáticamente (ver `http.ts` línea 35-43). Usar `http` en lugar de `axios` raw es mandatorio por convención del proyecto y garantiza que el token se envíe sin duplicar lógica. El interceptor de respuesta de `http` desenvuelve `{ success, data }` pero para el authorizer necesitamos la respuesta raw — se documenta cómo manejarlo.
  Alternativa descartada: Nueva instancia axios cruda — viola convención del proyecto y duplica la lógica de auth.

**DEC-07 — Store de notificaciones como Pinia composition API (setup store) con `persist: false`**
  Decisión: Usar el estilo Options API de Pinia (no composition) para alinear con el manual, pero sin `persist: true`.
  Justificación: Las notificaciones son datos de sesión, no deben persistirse entre recargas. El store de `auth` tiene `persist: true` porque el token necesita sobrevivir recargas; las notificaciones deben refetchearse desde el servidor al reconectar.
  Alternativa descartada: `persist: true` — causaría mostrar notificaciones stale de sesiones previas.

**DEC-08 — Permisos Spatie para notificaciones**
  Decisión: No se crean permisos Spatie para este módulo. Los endpoints filtran por `$request->user()->id` — cada usuario solo ve sus propias notificaciones. No hay operaciones admin sobre notificaciones de terceros en este alcance.
  Justificación: El sistema de notificaciones es inherentemente del usuario autenticado (self-service). Agregar permisos como `notifications.read` sin una vista de admin sería overhead innecesario.
  Alternativa descartada: Crear `notifications.read` + `notifications.admin` — fuera de alcance de esta iteración.

**DEC-09 — `created_by` y `updated_by` en la tabla `notifications`**
  Decisión: Se incluyen en la migración como `nullable()` sin FK constraint a `users`. El servicio los popula desde `Auth::id()` al crear.
  Justificación: El manual los incluye en el schema. Como pueden generarse desde jobs/artisan commands donde no hay usuario autenticado, deben ser nullable. Sin FK porque la relación es informal (auditoría, no integridad referencial).
  Alternativa descartada: FK obligatoria a users — rompería notificaciones generadas por el sistema.

**DEC-10 — Componente `NotificationBell.vue` como componente independiente en `modules/notifications/components/`**
  Decisión: Crear `NotificationBell.vue` en `front/src/modules/notifications/components/` e importarlo en `AppLayout.vue`.
  Justificación: La lógica de la campana (dropdown, badge, fetch, evento WS) es suficientemente compleja para no estar inlined en `AppLayout.vue`. Seguir el patrón de separación del proyecto (ver `ExportDrawer`, `AppUserMenu`, etc.).
  Alternativa descartada: Lógica directamente en `AppLayout.vue` — viola separación de responsabilidades y hace AppLayout demasiado acoplado.

**DEC-11 — `index` del controlador usa paginación; `latest` devuelve las últimas 10 sin paginar**
  Decisión: `GET /v1/notifications` devuelve paginado (15 por defecto). `GET /v1/notifications/latest` devuelve array fijo de 10 más recientes + `unread_count`, sin paginación.
  Justificación: `latest` es para el dropdown de la campana — necesita respuesta liviana y rápida. `index` es para una futura vista de listado completo con filtros y paginación.

---

## Cambios en BACKEND

### Archivos a modificar

#### `back/database/migrations/2026_05_18_181005_create_notifications_table.php`
**Cambio:** Reemplazar contenido completo. La versión actual usa `uuid('id')->primary()`, `morphs('notifiable')`, `text('data')` y `timestamp('read_at')` — formato Laravel Notifications default. Se reemplaza por estructura custom con `id BIGINT`, `guid`, `payload JSON`, `created_by`, `updated_by`, `deleted_at`.

**Contenido nuevo completo:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique()->comment('UUID generado automáticamente por HasGuid trait');
            $table->json('payload')->comment('Estructura libre: {title, description, url, type, ...}');
            $table->unsignedBigInteger('created_by')->nullable()->comment('ID del usuario que generó la notificación; null si fue el sistema');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

**Nota para el dev:** Si la migración ya fue corrida, ejecutar `php artisan migrate:rollback --step=1` (o `migrate:fresh` en desarrollo) antes de re-migrar.

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar import y binding para `NotificationRepositoryInterface` → `NotificationRepositoryEloquent`.

**Agregar en la sección `use` (imports):**
```php
use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Repositories\NotificationRepositoryEloquent;
```

**Agregar en el método `register()` junto al resto de bindings:**
```php
$this->app->bind(NotificationRepositoryInterface::class, NotificationRepositoryEloquent::class);
```

#### `back/routes/api.php`
**Cambio:** Agregar `Broadcast::routes()` antes del glob de archivos. El archivo actualmente solo contiene el foreach. Debe quedar así:

```php
<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

foreach (glob(__DIR__ . '/api/*.php') as $routeFile) {
    require $routeFile;
}
```

**Justificación:** `Broadcast::routes()` registra `/broadcasting/auth`. Debe ir antes del glob para no quedar dentro de ningún prefix group. Ver DEC-05.

#### `back/.env.example`
**Cambio:** Agregar sección Reverb. Agregar después de la línea `BROADCAST_CONNECTION=log`:

```env
# --- WebSockets / Reverb ---
# BROADCAST_CONNECTION=reverb

# REVERB_APP_ID=sav_app_id
# REVERB_APP_KEY=sav_app_key
# REVERB_APP_SECRET=sav_app_secret
# REVERB_HOST=0.0.0.0
# REVERB_PORT=8080
# REVERB_SCHEME=http
```

---

### Archivos a crear — Backend

#### `back/database/migrations/2026_05_22_100001_create_notification_recipients_table.php`
**Propósito:** Tabla pivot que vincula notificaciones con usuarios destinatarios y registra estado de lectura individual.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')
                  ->constrained('notifications')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->timestamp('read_at')->nullable()->comment('NULL = no leída');
            $table->timestamps();

            $table->unique(['notification_id', 'user_id']);
            $table->index('user_id');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
```

#### `back/routes/channels.php`
**Propósito:** Autorización de canales privados para Laravel Broadcasting. Archivo nuevo — no existía en el proyecto.

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Canal privado por usuario.
 * El frontend se suscribe a: private-app.user.{userId}
 * El callback recibe el User autenticado (via auth:sanctum) y el {userId} del canal.
 * Solo autoriza si el usuario autenticado ES ese userId.
 */
Broadcast::channel('app.user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId;
});
```

#### `back/config/broadcasting.php`
**Propósito:** Configuración del driver Reverb. Este archivo no existe en el proyecto — `laravel/reverb` lo publica con `php artisan reverb:install`, pero se documenta aquí el contenido esperado post-install para referencia y para el .env.

**Nota:** Este archivo es generado automáticamente por `php artisan reverb:install`. El dev NO debe crearlo manualmente; se incluye aquí como referencia de las claves de configuración. Si por alguna razón el archivo no se genera, el contenido mínimo requerido es:

```php
<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [
        'reverb' => [
            'driver'  => 'reverb',
            'key'     => env('REVERB_APP_KEY'),
            'secret'  => env('REVERB_APP_SECRET'),
            'app_id'  => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST', '0.0.0.0'),
                'port'   => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
```

#### `back/app/Models/Notification.php`
**Propósito:** Modelo Eloquent para notificaciones. Usa `HasGuid` del proyecto (no `HasUuids`). Incluye SoftDeletes como excepción documentada (ver DEC-04).

```php
<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = [
        'guid',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Usuarios destinatarios de esta notificación.
     * El pivot incluye read_at para estado de lectura individual.
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_recipients')
                    ->withPivot('read_at')
                    ->withTimestamps();
    }
}
```

#### `back/app/Models/NotificationRecipient.php`
**Propósito:** Modelo pivot que representa la relación notificación–destinatario con estado de lectura.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRecipient extends Model
{
    protected $fillable = [
        'notification_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

#### `back/app/Events/News.php`
**Propósito:** Evento de broadcasting que transporta una notificación a un usuario específico vía canal privado Reverb.

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class News implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $guid,
        public readonly array  $payload,
        public readonly int    $recipientUserId,
        public readonly bool   $isRead = false,
    ) {}

    /**
     * Canal privado por usuario: private-app.user.{recipientUserId}
     * Requiere autenticación en /broadcasting/auth
     *
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("app.user.{$this->recipientUserId}"),
        ];
    }

    /**
     * Nombre del evento en el frontend: '.app.event'
     * El punto inicial es obligatorio cuando viene de broadcastAs().
     */
    public function broadcastAs(): string
    {
        return 'app.event';
    }

    /**
     * Payload enviado al frontend vía WebSocket.
     */
    public function broadcastWith(): array
    {
        return [
            'event'   => 'news',
            'payload' => [
                'guid'    => $this->guid,
                'data'    => $this->payload,
                'user_id' => $this->recipientUserId,
                'is_read' => $this->isRead,
            ],
        ];
    }
}
```

#### `back/app/Contracts/Repositories/NotificationRepositoryInterface.php`
**Propósito:** Contrato del repositorio. El Service inyecta esta interface, nunca la implementación concreta.

```php
<?php

namespace App\Contracts\Repositories;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    public function findByGuid(string $guid): ?Notification;

    /**
     * Lista paginada de notificaciones del usuario, con su estado de lectura.
     *
     * @param  int    $userId  ID interno del usuario autenticado
     * @param  array  $params  Filtros: per_page, page
     * @return array{ notifications: LengthAwarePaginator, unread_count: int }
     */
    public function list(int $userId, array $params): array;

    /**
     * Últimas N notificaciones del usuario para el dropdown de la campana.
     *
     * @param  int  $userId
     * @param  int  $limit  Default: 10
     * @return array{ notifications: array<Notification>, unread_count: int }
     */
    public function latest(int $userId, int $limit = 10): array;

    /**
     * Cuenta notificaciones no leídas del usuario.
     */
    public function getUnreadCount(int $userId): int;

    /**
     * Crea una notificación con sus destinatarios.
     *
     * @param  array  $data  { payload: array, user_ids: int[], created_by: int|null }
     */
    public function create(array $data): Notification;
}
```

#### `back/app/Repositories/NotificationRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio. Filtra siempre por `user_id` del destinatario para garantizar que cada usuario solo vea sus propias notificaciones.

```php
<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationRepositoryEloquent extends BaseRepositoryEloquent implements NotificationRepositoryInterface
{
    protected function model(): string
    {
        return Notification::class;
    }

    public function findByGuid(string $guid): ?Notification
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    /**
     * Lista paginada. Filtra por destinatario y carga el pivot read_at
     * solo para el usuario solicitante.
     */
    public function list(int $userId, array $params): array
    {
        $perPage = (int) ($params['per_page'] ?? 15);

        $paginator = $this->newQuery()
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $userId))
            ->with(['recipients' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return [
            'notifications' => $paginator,
            'unread_count'  => $this->getUnreadCount($userId),
        ];
    }

    /**
     * Últimas $limit notificaciones para el dropdown de la campana.
     */
    public function latest(int $userId, int $limit = 10): array
    {
        $notifications = $this->newQuery()
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $userId))
            ->with(['recipients' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'notifications' => $notifications,
            'unread_count'  => $this->getUnreadCount($userId),
        ];
    }

    public function getUnreadCount(int $userId): int
    {
        return NotificationRecipient::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Crea la notificación y adjunta los destinatarios en la tabla pivot.
     * No dispara el evento de broadcasting — eso lo hace el Service.
     */
    public function create(array $data): Notification
    {
        /** @var Notification $notification */
        $notification = $this->model->newQuery()->create([
            'payload'    => $data['payload'],
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['created_by'] ?? null,
        ]);

        // syncWithoutDetaching: agrega destinatarios sin borrar los existentes
        $notification->recipients()->syncWithoutDetaching(
            collect($data['user_ids'])->mapWithKeys(fn ($id) => [$id => []])->all()
        );

        return $notification;
    }
}
```

#### `back/app/Services/NotificationService.php`
**Propósito:** Capa de negocio. Orquesta creación, despacho de eventos y marcado de lectura. Los otros services del sistema inyectan este service para enviar notificaciones.

```php
<?php

namespace App\Services;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Events\News;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
    ) {}

    /**
     * Crea una notificación y la despacha en tiempo real a cada destinatario.
     *
     * Uso desde otros Services/Jobs:
     *   $this->notificationService->store([
     *       'payload'  => ['title' => '...', 'description' => '...', 'url' => '/...', 'type' => 'info'],
     *       'user_ids' => [1, 2, 3],
     *   ]);
     *
     * @param  array  $data  { payload: array, user_ids: int[] }
     */
    public function store(array $data): Notification
    {
        $notification = $this->notificationRepository->create([
            'payload'    => $data['payload'],
            'user_ids'   => $data['user_ids'],
            'created_by' => Auth::id(),
        ]);

        // Despachar evento WebSocket para cada destinatario individualmente
        foreach ($data['user_ids'] as $userId) {
            event(new News(
                guid:            $notification->guid,
                payload:         $notification->payload,
                recipientUserId: (int) $userId,
                isRead:          false,
            ));
        }

        return $notification;
    }

    /**
     * Marca como leída la notificación $guid para el usuario $userId.
     * Solo actualiza el pivot del usuario — otros destinatarios no se afectan.
     */
    public function markAsRead(string $guid, int $userId): void
    {
        $notification = $this->notificationRepository->findByGuid($guid);

        if (! $notification) {
            return;
        }

        NotificationRecipient::where('notification_id', $notification->id)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Marca todas las notificaciones no leídas del usuario como leídas.
     */
    public function markAllAsRead(int $userId): void
    {
        NotificationRecipient::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Lista paginada de notificaciones del usuario.
     *
     * @return array{ notifications: LengthAwarePaginator, unread_count: int }
     */
    public function list(int $userId, array $params): array
    {
        return $this->notificationRepository->list($userId, $params);
    }

    /**
     * Últimas notificaciones para el dropdown de la campana.
     *
     * @return array{ notifications: \Illuminate\Support\Collection, unread_count: int }
     */
    public function latest(int $userId): array
    {
        return $this->notificationRepository->latest($userId, 10);
    }

    public function findByGuid(string $guid): ?Notification
    {
        return $this->notificationRepository->findByGuid($guid);
    }
}
```

#### `back/app/Http/Requests/Notifications/IndexNotificationRequest.php`
**Propósito:** Valida parámetros de paginación del listado.

```php
<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
```

#### `back/app/Http/Requests/Notifications/MarkAsReadRequest.php`
**Propósito:** Request para el endpoint `PATCH /{guid}/read`. No tiene body — la validación es solo el guard de autenticación y el parámetro de ruta. Se incluye de todas formas para mantener el patrón del proyecto (un FormRequest por endpoint de escritura).

```php
<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class MarkAsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // La validación de existencia del guid se hace en el controller
        return [];
    }
}
```

#### `back/app/Http/Resources/V1/NotificationResource.php`
**Propósito:** Resource API para serializar una notificación con su estado de lectura para el usuario autenticado.

**Firma principal:**
```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // El pivot read_at se carga condicionalmente desde la relación recipients
        // filtrada por user_id (ver repositorio). Si no está cargada, read_at = null.
        $readAt = null;
        if ($this->relationLoaded('recipients') && $this->recipients->isNotEmpty()) {
            $readAt = $this->recipients->first()?->pivot?->read_at?->toISOString();
        }

        return [
            'guid'       => $this->guid,
            'payload'    => $this->payload,   // array: {title, description, url, type, ...}
            'read_at'    => $readAt,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

#### `back/app/Http/Controllers/V1/NotificationController.php`
**Propósito:** Controlador HTTP. Hereda de `Controller` (que usa `ApiResponseTrait`). Usa GUID en todos los parámetros de ruta.

```php
<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexNotificationRequest;
use App\Http\Requests\Notifications\MarkAsReadRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * GET /v1/notifications
     * Lista paginada de notificaciones del usuario autenticado.
     */
    public function index(IndexNotificationRequest $request): JsonResponse
    {
        try {
            $result = $this->notificationService->list(
                $request->user()->id,
                $request->validated(),
            );

            $paginator = $result['notifications'];

            return $this->makeSuccess([
                'notifications' => [
                    'data'         => NotificationResource::collection($paginator->items()),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                ],
                'unread_count' => $result['unread_count'],
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/notifications/latest
     * Últimas 10 notificaciones + unread_count para el dropdown de la campana.
     */
    public function latest(Request $request): JsonResponse
    {
        try {
            $result = $this->notificationService->latest($request->user()->id);

            return $this->makeSuccess([
                'notifications' => NotificationResource::collection($result['notifications']),
                'unread_count'  => $result['unread_count'],
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * PATCH /v1/notifications/{guid}/read
     * Marca una notificación específica como leída para el usuario autenticado.
     */
    public function markAsRead(MarkAsReadRequest $request, string $guid): JsonResponse
    {
        try {
            $notification = $this->notificationService->findByGuid($guid);

            if (! $notification) {
                return $this->makeNotFound('Notificación no encontrada.');
            }

            $this->notificationService->markAsRead($guid, $request->user()->id);

            return $this->makeSuccess(null, 'Notificación marcada como leída.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * PATCH /v1/notifications/read-all
     * Marca todas las notificaciones no leídas del usuario como leídas.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $this->notificationService->markAllAsRead($request->user()->id);

            return $this->makeSuccess(null, 'Todas las notificaciones marcadas como leídas.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

#### `back/routes/api/notifications.php`
**Propósito:** Rutas del módulo de notificaciones.

**Atención al orden:** `latest` y `read-all` deben definirse ANTES de `{guid}` para que Laravel no trate la literal `latest` o `read-all` como un guid.

```php
<?php

use App\Http\Controllers\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('/',           [NotificationController::class, 'index']);
    Route::get('/latest',     [NotificationController::class, 'latest']);
    Route::patch('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::patch('/{guid}/read', [NotificationController::class, 'markAsRead']);
});
```

---

### Variables de entorno — Backend

**Agregar en `.env` (local del dev, no en `.env.example`):**
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=sav_app_id
REVERB_APP_KEY=sav_app_key
REVERB_APP_SECRET=sav_app_secret
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
```

Los valores de `REVERB_APP_ID`, `REVERB_APP_KEY` y `REVERB_APP_SECRET` son strings arbitrarios en desarrollo local. En producción deben ser secretos seguros.

---

### Comandos de instalación — Backend

```bash
# Desde el directorio back/
composer require laravel/reverb

# Publicar configuración y archivos de Reverb (genera config/broadcasting.php y channels.php stub)
php artisan reverb:install

# IMPORTANTE: channels.php debe ser REEMPLAZADO con el contenido del plan (ver arriba).
# reverb:install puede generar un channels.php en routes/ — verificar y reemplazar.

# Correr migraciones (en desarrollo: migrate:fresh si la tabla vieja ya existía)
php artisan migrate
# O si la tabla ya estaba migrada:
# php artisan migrate:rollback --step=1  (solo si es la última migración)
# php artisan migrate
```

---

### Contratos de endpoints

**GET /v1/notifications**
Request params: `per_page` (int, opcional), `page` (int, opcional)
Response 200:
```json
{
  "success": true,
  "data": {
    "notifications": {
      "data": [
        {
          "guid": "uuid-string",
          "payload": {
            "title": "Operación por vencer",
            "description": "La operación #42 vence en 24 horas.",
            "url": "/operations/some-guid",
            "type": "warning"
          },
          "read_at": null,
          "created_at": "2026-05-22T10:00:00.000000Z"
        }
      ],
      "current_page": 1,
      "last_page": 3,
      "per_page": 15,
      "total": 42
    },
    "unread_count": 7
  }
}
```

**GET /v1/notifications/latest**
Response 200:
```json
{
  "success": true,
  "data": {
    "notifications": [ /* array de NotificationResource, máx 10 */ ],
    "unread_count": 7
  }
}
```

**PATCH /v1/notifications/{guid}/read**
Body: vacío
Response 200: `{ "success": true, "data": null, "message": "Notificación marcada como leída." }`
Errores:
| HTTP | Cuándo |
|------|--------|
| 404  | guid no existe |
| 401  | no autenticado |

**PATCH /v1/notifications/read-all**
Body: vacío
Response 200: `{ "success": true, "data": null, "message": "Todas las notificaciones marcadas como leídas." }`

**POST /broadcasting/auth** (manejado por Laravel Reverb + Broadcast::routes())
Body: `{ "socket_id": "...", "channel_name": "private-app.user.{id}" }`
Response 200: `{ "auth": "app-key:signature" }`
Errores: 403 si el usuario autenticado no coincide con el `{id}` del canal.

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — NotificationController:**
- `GET /v1/notifications` sin autenticación → 401
- `GET /v1/notifications` autenticado → 200 con estructura paginada y `unread_count`
- `GET /v1/notifications` solo devuelve notificaciones del usuario autenticado (no de otros usuarios — aislamiento)
- `GET /v1/notifications/latest` → 200, máximo 10 items, incluye `unread_count`
- `PATCH /v1/notifications/{guid}/read` → 200, `read_at` se setea en el pivot
- `PATCH /v1/notifications/{guid}/read` con guid de otro usuario → 404 (no expone existencia)
- `PATCH /v1/notifications/read-all` → 200, todos los `read_at` del usuario son != null
- `PATCH /v1/notifications/{guid}/read` con guid inexistente → 404

**Unit tests — NotificationService:**
- `store()` crea un registro en `notifications` y uno en `notification_recipients` por cada `user_id`
- `store()` despacha un evento `News` por cada `user_id`
- `markAsRead()` solo actualiza el pivot del usuario especificado (los otros destinatarios no cambian)
- `markAllAsRead()` actualiza todos los pivots del usuario donde `read_at` es null

**Unit tests — NotificationRepositoryEloquent:**
- `list()` filtra por `user_id` del destinatario, no trae notificaciones de otros usuarios
- `getUnreadCount()` retorna 0 si todas están leídas, N si hay N sin leer

---

## Cambios en FRONTEND

### Comandos de instalación — Frontend

```bash
# Desde el directorio front/
npm install laravel-echo pusher-js
```

### Variables de entorno — Frontend

**Agregar en `front/.env.local` (desarrollo):**
```env
VITE_REVERB_APP_KEY=sav_app_key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
VITE_REVERB_BROADCAST_AUTH=http://localhost:8001/api/broadcasting/auth
```

**Crear `front/.env.example` (si no existe):**
```env
VITE_APP_NAME="Mi Proyecto"
VITE_API_BASE_URL=http://localhost:8001/api

# WebSockets / Reverb
VITE_REVERB_APP_KEY=
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
VITE_REVERB_BROADCAST_AUTH=http://localhost:8001/api/broadcasting/auth
```

**Atención:** `VITE_REVERB_APP_KEY` debe coincidir exactamente con `REVERB_APP_KEY` del backend.

---

### Archivos a crear — Frontend

#### `front/src/modules/notifications/types/notification.types.ts`
**Propósito:** Tipos TypeScript del módulo.

```typescript
export type NotificationType = 'info' | 'warning' | 'error' | 'success'

export interface NotificationPayload {
  title: string
  description: string
  url?: string
  type?: NotificationType
  [key: string]: unknown  // extensible para futuros campos
}

export interface NotificationItem {
  guid: string
  payload: NotificationPayload
  read_at: string | null
  created_at: string
}

export interface NotificationListResponse {
  notifications: {
    data: NotificationItem[]
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  unread_count: number
}

export interface NotificationLatestResponse {
  notifications: NotificationItem[]
  unread_count: number
}

// Payload recibido por WebSocket desde el evento News::broadcastWith()
export interface NotificationRealtimePayload {
  guid: string
  data: NotificationPayload
  user_id: number
  is_read: boolean
}

export interface NotificationListParams {
  per_page?: number
  page?: number
}
```

#### `front/src/modules/notifications/api/notifications.api.ts`
**Propósito:** Funciones de llamada HTTP al backend. Usa `http` del proyecto.

**Nota importante:** El interceptor de respuesta de `http` desenvuelve `{ success, data }` automáticamente (ver `http.ts` línea 48-58). Por eso las funciones trabajan directamente con `response.data` ya desenvuelto.

```typescript
import { http } from '@/core/api/http'
import type {
  NotificationListParams,
  NotificationListResponse,
  NotificationLatestResponse,
} from '../types/notification.types'

export async function listNotificationsApi(
  params: NotificationListParams = {},
  signal?: AbortSignal,
): Promise<NotificationListResponse> {
  const response = await http.get<NotificationListResponse>('/v1/notifications', { params, signal })
  return response.data
}

export async function getLatestNotificationsApi(): Promise<NotificationLatestResponse> {
  const response = await http.get<NotificationLatestResponse>('/v1/notifications/latest')
  return response.data
}

export async function markAsReadApi(guid: string): Promise<void> {
  await http.patch(`/v1/notifications/${guid}/read`)
}

export async function markAllAsReadApi(): Promise<void> {
  await http.patch('/v1/notifications/read-all')
}
```

#### `front/src/services/eventBusService.ts`
**Propósito:** Pub/sub minimalista desacoplado. Permite que `socketService` emita eventos sin importar directamente los componentes ni el store.

```typescript
type EventCallback<T = unknown> = (payload: T) => void

class EventBusService {
  private listeners: Record<string, EventCallback[]> = {}

  on<T = unknown>(event: string, callback: EventCallback<T>): () => void {
    if (!this.listeners[event]) {
      this.listeners[event] = []
    }
    this.listeners[event].push(callback as EventCallback)

    // Retorna función de cleanup — usada por useEvent composable
    return () => this.off(event, callback as EventCallback)
  }

  off(event: string, callback: EventCallback): void {
    if (!this.listeners[event]) return
    this.listeners[event] = this.listeners[event].filter((cb) => cb !== callback)
  }

  emit<T = unknown>(event: string, payload: T): void {
    if (!this.listeners[event]) return
    this.listeners[event].forEach((cb) => cb(payload))
  }
}

export const eventBus = new EventBusService()
export default eventBus
```

#### `front/src/services/echoService.ts`
**Propósito:** Singleton que crea y gestiona la instancia de Laravel Echo con Reverb. Usa el `http` del proyecto en el authorizer para que el token Bearer se envíe automáticamente.

**Problema del interceptor:** `http` tiene un interceptor que desenvuelve `{ success, data }`. En el authorizer de Echo, necesitamos la respuesta raw (el objeto de auth de Pusher). Se usa `http` directamente porque su interceptor solo desenvuelve cuando `success === true` — si la respuesta de `/broadcasting/auth` es el objeto `{ auth: "..." }` sin wrapper `success`, el interceptor la deja pasar. El backend con `Broadcast::routes()` devuelve el objeto raw de Pusher, no el wrapper del proyecto. Esto es correcto y el interceptor lo pasa sin modificar (ver rama `return response` al final del interceptor).

```typescript
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { http } from '@/core/api/http'

// Necesario para que Echo use la instancia de Pusher
window.Pusher = Pusher

const REVERB_APP_KEY    = import.meta.env.VITE_REVERB_APP_KEY    as string
const REVERB_HOST       = import.meta.env.VITE_REVERB_HOST       as string
const REVERB_PORT       = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)
const REVERB_SCHEME     = import.meta.env.VITE_REVERB_SCHEME     as string
const BROADCAST_AUTH_URL = import.meta.env.VITE_REVERB_BROADCAST_AUTH as string

let echoInstance: Echo | null = null

export function getEcho(): Echo {
  if (echoInstance) return echoInstance

  echoInstance = new Echo({
    broadcaster:       'reverb',
    key:               REVERB_APP_KEY,
    wsHost:            REVERB_HOST,
    wsPort:            REVERB_SCHEME === 'https' ? 443 : REVERB_PORT,
    wssPort:           REVERB_SCHEME === 'https' ? 443 : REVERB_PORT,
    forceTLS:          REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    /**
     * Authorizer custom: usa la instancia `http` del proyecto en lugar de axios crudo.
     * Esto garantiza que el interceptor inyecte el Bearer token automáticamente.
     * El endpoint /broadcasting/auth NO usa el wrapper { success, data } del proyecto
     * — devuelve el objeto raw de Pusher { auth: "..." }, que el interceptor pasa sin modificar.
     */
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (error: boolean, data: unknown) => void,
      ) => {
        http
          .post(BROADCAST_AUTH_URL, {
            socket_id:    socketId,
            channel_name: channel.name,
          })
          .then((res) => callback(false, res.data))
          .catch((err) => callback(true, err))
      },
    }),
  })

  return echoInstance
}

export function destroyEcho(): void {
  if (echoInstance) {
    echoInstance.disconnect()
    echoInstance = null
  }
}
```

**Nota de tipado:** `laravel-echo` y `pusher-js` no tienen declaraciones de tipos perfectas en todos los entornos. Si TypeScript se queja de `window.Pusher`, agregar en `front/src/vite-env.d.ts`:
```typescript
interface Window {
  Pusher: typeof import('pusher-js')
}
```

#### `front/src/services/socketService.ts`
**Propósito:** Gestiona la suscripción al canal privado del usuario y re-emite los eventos al eventBus.

```typescript
import { getEcho, destroyEcho } from './echoService'
import eventBus from './eventBusService'
import type Echo from 'laravel-echo'

class SocketService {
  private connected = false
  private echo: Echo | null = null
  private userId: number | null = null

  connect(userId: number): void {
    if (this.connected) return

    this.echo   = getEcho()
    this.userId = userId

    /**
     * Canal privado: private-app.user.{userId}
     * El '.app.event' con punto inicial es obligatorio cuando el nombre
     * viene de broadcastAs() en el backend.
     */
    this.echo
      .private(`app.user.${userId}`)
      .listen('.app.event', (data: { event: string; payload: unknown }) => {
        // Re-emite al eventBus con el nombre del evento como clave
        eventBus.emit(data.event, data.payload)
      })

    this.connected = true
  }

  disconnect(): void {
    if (!this.connected) return

    if (this.echo && this.userId !== null) {
      this.echo.leave(`app.user.${this.userId}`)
    }
    destroyEcho()

    this.connected = false
    this.echo      = null
    this.userId    = null
  }

  isConnected(): boolean {
    return this.connected
  }
}

export const socketService = new SocketService()
export default socketService
```

#### `front/src/composables/useEvent.ts`
**Propósito:** Composable Vue que suscribe al eventBus durante el ciclo de vida del componente y se limpia automáticamente al desmontarlo.

```typescript
import { onMounted, onUnmounted } from 'vue'
import eventBus from '@/services/eventBusService'

/**
 * Suscribe al evento $event del eventBus mientras el componente está montado.
 * Se desuscribe automáticamente en onUnmounted.
 *
 * Uso:
 *   useEvent('news', (payload: NotificationRealtimePayload) => {
 *       notificationStore.pushRealtime(payload)
 *   })
 */
export function useEvent<T = unknown>(event: string, callback: (payload: T) => void): void {
  let unsubscribe: (() => void) | null = null

  onMounted(() => {
    unsubscribe = eventBus.on<T>(event, callback)
  })

  onUnmounted(() => {
    if (unsubscribe) {
      unsubscribe()
      unsubscribe = null
    }
  })
}
```

#### `front/src/modules/notifications/stores/notifications.store.ts`
**Propósito:** Estado global de notificaciones. Gestiona lista, unreadCount, latest y pushRealtime.

```typescript
import { defineStore } from 'pinia'
import {
  getLatestNotificationsApi,
  listNotificationsApi,
  markAsReadApi,
  markAllAsReadApi,
} from '../api/notifications.api'
import type { NotificationItem, NotificationRealtimePayload } from '../types/notification.types'

interface NotificationPagination {
  currentPage: number
  lastPage: number
  perPage: number
  total: number
}

export const useNotificationStore = defineStore('notifications', {
  state: () => ({
    unreadCount: 0 as number,
    list: [] as NotificationItem[],
    latest: [] as NotificationItem[],
    pagination: {
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: 0,
    } as NotificationPagination,
    loading: false as boolean,
  }),

  actions: {
    /**
     * Carga la lista paginada completa (para vista de notificaciones).
     */
    async fetch(page = 1): Promise<void> {
      this.loading = true
      try {
        const res = await listNotificationsApi({ page, per_page: this.pagination.perPage })
        this.list       = res.notifications.data
        this.unreadCount = res.unread_count
        this.pagination = {
          currentPage: res.notifications.current_page,
          lastPage:    res.notifications.last_page,
          perPage:     res.notifications.per_page,
          total:       res.notifications.total,
        }
      } finally {
        this.loading = false
      }
    },

    /**
     * Carga las últimas 10 notificaciones para el dropdown de la campana.
     */
    async fetchLatest(): Promise<void> {
      const res        = await getLatestNotificationsApi()
      this.latest      = res.notifications
      this.unreadCount = res.unread_count
    },

    /**
     * Marca una notificación como leída y actualiza el estado local sin refetch.
     */
    async markAsRead(guid: string): Promise<void> {
      await markAsReadApi(guid)

      const updateItem = (item: NotificationItem) => {
        if (item.guid === guid && item.read_at === null) {
          item.read_at     = new Date().toISOString()
          this.unreadCount = Math.max(0, this.unreadCount - 1)
        }
      }

      this.list.forEach(updateItem)
      this.latest.forEach(updateItem)
    },

    /**
     * Marca todas como leídas y actualiza el estado local sin refetch.
     */
    async markAllAsRead(): Promise<void> {
      await markAllAsReadApi()

      const now = new Date().toISOString()
      this.list.forEach((item) => { item.read_at = item.read_at ?? now })
      this.latest.forEach((item) => { item.read_at = item.read_at ?? now })
      this.unreadCount = 0
    },

    /**
     * Inserta una notificación recibida por WebSocket en tiempo real.
     * Se llama desde NotificationBell.vue via useEvent('news', ...).
     * Evita duplicados verificando el guid.
     */
    pushRealtime(notification: NotificationRealtimePayload): void {
      if (this.latest.some((n) => n.guid === notification.guid)) return

      const item: NotificationItem = {
        guid:       notification.guid,
        payload:    notification.data,
        read_at:    notification.is_read ? new Date().toISOString() : null,
        created_at: new Date().toISOString(),
      }

      this.latest.unshift(item)

      // Mantener máximo 10 en latest
      if (this.latest.length > 10) {
        this.latest.pop()
      }

      if (!notification.is_read) {
        this.unreadCount++
      }
    },

    /**
     * Limpia el estado al hacer logout.
     */
    reset(): void {
      this.$reset()
    },
  },
})
```

#### `front/src/modules/notifications/composables/useNotifications.ts`
**Propósito:** Composable que expone el store como interfaz de uso en componentes.

```typescript
import { storeToRefs } from 'pinia'
import { useNotificationStore } from '../stores/notifications.store'

export function useNotifications() {
  const store = useNotificationStore()
  const { unreadCount, list, latest, pagination, loading } = storeToRefs(store)

  return {
    unreadCount,
    list,
    latest,
    pagination,
    loading,
    fetch:        store.fetch,
    fetchLatest:  store.fetchLatest,
    markAsRead:   store.markAsRead,
    markAllAsRead: store.markAllAsRead,
    pushRealtime: store.pushRealtime,
  }
}
```

#### `front/src/modules/notifications/components/NotificationBell.vue`
**Propósito:** Componente de campana con dropdown de notificaciones recientes. Reemplaza el `<button>` hardcodeado en `AppLayout.vue`.

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { BellOutlined } from '@ant-design/icons-vue'
import { useNotifications } from '../composables/useNotifications'
import { useEvent } from '@/composables/useEvent'
import type { NotificationRealtimePayload } from '../types/notification.types'

const { unreadCount, latest, fetchLatest, markAsRead, markAllAsRead, pushRealtime } = useNotifications()

const isOpen = ref(false)
const wrapperRef = ref<HTMLElement | null>(null)

onMounted(() => {
  fetchLatest()
})

// Cuando llega una notificación por WebSocket
useEvent<NotificationRealtimePayload>('news', (payload) => {
  pushRealtime(payload)
})

function onDocClick(e: MouseEvent) {
  if (wrapperRef.value && !wrapperRef.value.contains(e.target as Node)) {
    isOpen.value = false
  }
}

import { onUnmounted } from 'vue'
onMounted(()   => document.addEventListener('click', onDocClick, true))
onUnmounted(() => document.removeEventListener('click', onDocClick, true))
</script>

<template>
  <div class="notif-bell-wrapper" ref="wrapperRef">
    <button
      class="dash-icon-btn notif-bell-btn"
      title="Notificaciones"
      @click="isOpen = !isOpen"
    >
      <BellOutlined />
      <span v-if="unreadCount > 0" class="notif-badge">
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <Transition name="notif-pop">
      <div v-if="isOpen" class="notif-dropdown">
        <div class="notif-dropdown-header">
          <span class="notif-dropdown-title">Notificaciones</span>
          <button
            v-if="unreadCount > 0"
            class="notif-mark-all-btn"
            @click="markAllAsRead"
          >
            Marcar todas como leídas
          </button>
        </div>

        <div class="notif-dropdown-list">
          <div
            v-if="latest.length === 0"
            class="notif-empty"
          >
            No tenés notificaciones.
          </div>

          <div
            v-for="item in latest"
            :key="item.guid"
            class="notif-item"
            :class="{ 'notif-item--unread': !item.read_at }"
            @click="markAsRead(item.guid)"
          >
            <div class="notif-item-title">{{ item.payload?.title }}</div>
            <div class="notif-item-desc">{{ item.payload?.description }}</div>
            <div class="notif-item-time">{{ item.created_at }}</div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
```

**Nota de estilos:** Los nombres de clase (`notif-bell-wrapper`, `notif-badge`, etc.) siguen el patrón BEM del proyecto (ver `dash-*` en AppLayout). El dev debe agregar los estilos en el `<style scoped>` o en el archivo de estilos global de layouts según el patrón del proyecto.

---

### Archivos a modificar — Frontend

#### `front/src/App.vue`
**Cambio:** Agregar watch de `authStore` para conectar/desconectar el WebSocket según el estado de autenticación.

**Antes:**
```vue
<script setup lang="ts">
</script>

<template>
    <RouterView />
</template>
```

**Después:**
```vue
<script setup lang="ts">
import { watch, onMounted } from 'vue'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import socketService from '@/services/socketService'

const authStore = useAuthStore()

// Conectar al cargar la app si ya había sesión persistida
onMounted(() => {
  if (authStore.isAuthenticated && authStore.user?.id) {
    socketService.connect(authStore.user.id)
  }
})

// Reaccionar a cambios de auth: login → conectar, logout → desconectar
watch(
  () => [authStore.isAuthenticated, authStore.user?.id] as const,
  ([isAuth, userId]) => {
    if (isAuth && userId) {
      socketService.connect(userId)
    } else {
      socketService.disconnect()
    }
  },
)
</script>

<template>
    <RouterView />
</template>
```

#### `front/src/layouts/AppLayout.vue`
**Cambio:** Reemplazar el `<button class="dash-icon-btn" title="Notificaciones"><BellOutlined /></button>` (líneas 40-42) por el componente `NotificationBell`. Agregar import del componente y remover el import directo de `BellOutlined` si ya no se usa en otro lado del mismo archivo (en este caso `BellOutlined` solo estaba en el botón, así que se puede quitar el import).

**Sección `<script setup>` — agregar import:**
```typescript
import NotificationBell from '@/modules/notifications/components/NotificationBell.vue'
```

**Remover import** `BellOutlined` de `@ant-design/icons-vue` (ya no se usa en este archivo).

**Sección `<template>` — reemplazar:**
```html
<!-- Antes: -->
<button class="dash-icon-btn" title="Notificaciones">
    <BellOutlined />
</button>

<!-- Después: -->
<NotificationBell />
```

#### `front/src/vite-env.d.ts` (si existe) o crear el archivo
**Cambio:** Agregar declaración global para `window.Pusher` requerida por `echoService.ts`.

**Buscar el archivo:**
```
front/src/vite-env.d.ts
```

**Agregar al final del archivo:**
```typescript
interface Window {
  Pusher: typeof import('pusher-js')
}
```

Si el archivo no existe, crearlo con:
```typescript
/// <reference types="vite/client" />

interface Window {
  Pusher: typeof import('pusher-js')
}
```

---

## Orden de implementación

### Fase 1 — Backend base (sin WebSocket todavía)

1. **Instalar Reverb:** ejecutar `composer require laravel/reverb` en el directorio `back/`.
2. **Publicar config de Reverb:** ejecutar `php artisan reverb:install` en `back/`. Verificar que se generaron `config/broadcasting.php` y `routes/channels.php` (stub).
3. **Modificar migración existente:** reemplazar el contenido de `back/database/migrations/2026_05_18_181005_create_notifications_table.php` con el contenido del plan (Paso "Archivos a modificar").
4. **Crear migración de notification_recipients:** crear `back/database/migrations/2026_05_22_100001_create_notification_recipients_table.php` con el contenido del plan.
5. **Correr migraciones:** si la tabla `notifications` ya existía, hacer rollback o `migrate:fresh` en dev. Luego `php artisan migrate`.
6. **Agregar variables Reverb al `.env` del backend:** `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`.
7. **Crear modelos:** `back/app/Models/Notification.php` y `back/app/Models/NotificationRecipient.php`.
8. **Crear Interface:** `back/app/Contracts/Repositories/NotificationRepositoryInterface.php`.
9. **Crear Repositorio Eloquent:** `back/app/Repositories/NotificationRepositoryEloquent.php`.
10. **Registrar binding:** modificar `back/app/Providers/AppServiceProvider.php` agregando el bind de `NotificationRepositoryInterface → NotificationRepositoryEloquent`.
11. **Crear Service:** `back/app/Services/NotificationService.php`.
12. **Crear FormRequests:** `back/app/Http/Requests/Notifications/IndexNotificationRequest.php` y `MarkAsReadRequest.php`.
13. **Crear Resource:** `back/app/Http/Resources/V1/NotificationResource.php`.
14. **Crear Controller:** `back/app/Http/Controllers/V1/NotificationController.php`.
15. **Crear archivo de rutas:** `back/routes/api/notifications.php`.
16. **Modificar `routes/api.php`:** agregar `Broadcast::routes()` antes del glob.
17. **Reemplazar `routes/channels.php`:** con el contenido del plan (sobreescribir el stub generado por reverb:install si existe, o crear si no existe).
18. **Verificar endpoints con Postman/HTTP client:** probar los 4 endpoints sin WebSocket todavía. Verificar que `GET /v1/notifications/latest` devuelve la estructura esperada.

### Fase 2 — WebSocket activo

19. **Configurar y arrancar Reverb:** `php artisan reverb:start --host=0.0.0.0 --port=8080`. Verificar que levanta sin errores.
20. **Crear evento:** `back/app/Events/News.php`.
21. **Probar broadcast manual:** desde `php artisan tinker`, crear una notificación de prueba llamando directamente `event(new App\Events\News('test-guid', ['title'=>'Test'], 1))` y verificar en los logs de Reverb que el evento se envía.

### Fase 3 — Frontend

22. **Instalar dependencias frontend:** `npm install laravel-echo pusher-js` en `front/`.
23. **Agregar variables de entorno frontend:** modificar `front/.env.local` con los valores Reverb.
24. **Crear/actualizar `front/src/vite-env.d.ts`:** agregar declaración `Window.Pusher`.
25. **Crear tipos:** `front/src/modules/notifications/types/notification.types.ts`.
26. **Crear api functions:** `front/src/modules/notifications/api/notifications.api.ts`.
27. **Crear eventBusService:** `front/src/services/eventBusService.ts`.
28. **Crear echoService:** `front/src/services/echoService.ts`.
29. **Crear socketService:** `front/src/services/socketService.ts`.
30. **Crear composable useEvent:** `front/src/composables/useEvent.ts`.
31. **Crear store:** `front/src/modules/notifications/stores/notifications.store.ts`.
32. **Crear composable useNotifications:** `front/src/modules/notifications/composables/useNotifications.ts`.
33. **Crear componente NotificationBell:** `front/src/modules/notifications/components/NotificationBell.vue`.
34. **Modificar App.vue:** agregar lógica de connect/disconnect del socket.
35. **Modificar AppLayout.vue:** reemplazar botón hardcodeado por `<NotificationBell />`.
36. **Verificar integración end-to-end:** autenticarse en el frontend, verificar que el socket se conecta (ver Network tab → WS), desde tinker emitir un evento y verificar que el badge de la campana incrementa.
37. **Correr tests** del backend: `php artisan test`.

---

## Riesgos y consideraciones

### Riesgo crítico — SoftDeletes en Notification
La regla dura #12 del proyecto dice "sin soft deletes". `Notification` incluye `deleted_at` por requerimiento explícito del manual de referencia. Esta es la única excepción en el proyecto. Si en el futuro se decide eliminar soft deletes globalmente, este modelo debe revisarse primero.

### Riesgo — Interceptor de http en el authorizer de Echo
El interceptor de respuesta de `http` desenvuelve `{ success: true, data: ... }`. La respuesta de `/broadcasting/auth` de Laravel Reverb devuelve `{ auth: "app-key:signature" }` directamente (sin wrapper `success`). El interceptor tiene la lógica `if (payload['success'] === true) unwrap` — si `success` no está en el payload, cae al `return response` al final, devolviendo la respuesta sin modificar. Esto es correcto. Sin embargo, si en el futuro el proyecto agrega un wrapper a `/broadcasting/auth`, el authorizer dejará de funcionar. Documentado como supuesto en el `echoService.ts`.

### Riesgo — Orden de rutas en notifications.php
`/latest` y `/read-all` deben estar definidas ANTES de `/{guid}/read` en el archivo de rutas. Si se invierten, Laravel intentará tratar la literal "latest" o "read-all" como un guid, resultando en un 404 o comportamiento incorrecto. El plan ya establece el orden correcto — el dev no debe reordenar.

### Riesgo — `reverb:install` genera channels.php stub
`php artisan reverb:install` puede generar un `routes/channels.php` con contenido de ejemplo. El dev DEBE reemplazarlo con el contenido del plan (el canal `app.user.{userId}` con la verificación de identidad). Si se deja el stub sin modificar, el canal privado no autorizará a nadie o autorizará a todos.

### Riesgo — Declaración Window.Pusher en TypeScript
`echoService.ts` hace `window.Pusher = Pusher`. TypeScript estricto rechaza la asignación a propiedades no declaradas en `window`. Si no se agrega la declaración en `vite-env.d.ts`, el build de TypeScript fallará (`vue-tsc -b`). El paso 24 del orden de implementación lo cubre — pero debe hacerse ANTES de compilar el echoService.

### Riesgo — `REVERB_APP_KEY` debe coincidir entre back y front
Si `VITE_REVERB_APP_KEY` en el frontend no coincide exactamente con `REVERB_APP_KEY` en el backend, Echo rechazará la conexión con error 403. Error común en setup inicial.

### Riesgo — `ShouldBroadcastNow` y el queue worker
`ShouldBroadcastNow` ejecuta el broadcast sincrónicamente en el request HTTP. Si la lista de `user_ids` es larga (ej: 1000 destinatarios), el loop de `event(new News(...))` en `NotificationService::store()` bloqueará el request. Para esta iteración esto es aceptable (las notificaciones de SAV tienen pocos destinatarios). Si en el futuro se usan broadcasts masivos, migrar a `ShouldBroadcast` con queue.

### Riesgo — `persist: false` en el notification store
El store no persiste datos entre recargas de página. Al refrescar el browser, el usuario verá la campana sin badge hasta que el componente llame a `fetchLatest()` en `onMounted`. Esto es comportamiento esperado y correcto.

### Riesgo — Conflicto con el trait `Notifiable` en User
`User` usa el trait `Notifiable` (de Laravel). Esto agrega el método `notifications()` como morphMany al modelo `User`, que podría colisionar nominalmente con la relación `notifications()` que podría agregarse. En este plan, la relación se modela como `BelongsToMany` en `Notification` con `recipients()` — no se agrega una relación inversa en `User`. Si en el futuro se necesita `$user->notifications()` como BelongsToMany, colisionará con la relación `notifications()` de `Notifiable`. Solución futura: renombrar la relación a `$user->receivedNotifications()`.

### Riesgo arquitectónico — Multi-tenant
SAV es multi-tenant (cada vet es un tenant). En esta iteración el módulo de notificaciones NO está en el contexto veterinario — es una infraestructura transversal de la plataforma (como el módulo de usuarios o exports). Las notificaciones no están scoped a un tenant específico. Si en el futuro las notificaciones deben ser por-tenant (ej: "notificación de alerta veterinaria"), habrá que agregar `vet_id` a la tabla y filtrar por él. Marcado como deuda técnica.

---

## Pendientes / fuera de alcance

- **Vista completa de notificaciones:** Una página dedicada con listado paginado, filtros por tipo/fecha y marcado masivo. Esta iteración solo implementa el dropdown de la campana con las últimas 10.
- **Notificaciones desde jobs/eventos del dominio veterinario:** El servicio `NotificationService::store()` ya está preparado para ser inyectado en cualquier Service o Job. La integración con protocolos reproductivos, alertas veterinarias, etc. es responsabilidad de las iteraciones correspondientes de esos módulos.
- **Notificaciones push (browser notifications):** La Web Notifications API para mostrar notificaciones aunque el tab no esté enfocado. Fuera de alcance de esta iteración.
- **Filtros en `GET /v1/notifications`:** El `IndexNotificationRequest` solo valida `per_page` y `page`. Filtros por tipo, fecha, módulo y estado de lectura quedan para la vista completa.
- **Scoping multi-tenant en notificaciones:** Ver riesgo arquitectónico arriba. Fuera de alcance hasta que se defina el modelo de tenant para notificaciones.
- **Sonido/vibración al recibir notificación en tiempo real:** Mejora UX futura.
- **Eliminar notificaciones:** No hay endpoint `DELETE` en este plan. Las notificaciones usan soft deletes pero no hay interfaz para borrarlas.
- **Agregar `'notifications.read'` como permiso Spatie:** No se agrega en esta iteración. Ver DEC-08.
