# Manual de Implementación — Módulo de Notificaciones con WebSockets

## Tecnologías utilizadas

| Capa | Tecnología |
|---|---|
| Backend | Laravel 11, Laravel Reverb (WebSocket server), Laravel Broadcasting |
| Frontend | Vue 3, Pinia, Laravel Echo, Pusher-JS |
| Protocolo | WebSocket (ws/wss) con canales privados autenticados |
| Patrón | Service + Repository, Event Bus desacoplado |

---

## Arquitectura general

```
[Acción en Backend]
        │
        ▼
[NotificationService::store()]
        │
        ├─► Crea registro en BD (notifications + notification_recipients)
        │
        └─► event(new News(...)) ──► Laravel Reverb
                                           │
                                    canal privado:
                               private app.user.{userId}
                                           │
                                    [Frontend - Echo]
                                           │
                               socketService recibe .app.event
                                           │
                               eventBus.emit('news', payload)
                                           │
                        ┌──────────────────┴──────────────────┐
                        ▼                                       ▼
               AppTopbar.vue                          NotificationsTab.vue
         pushRealtime() → badge +1                  refetch() → lista actualizada
```

---

## PARTE 1 — Backend

### 1.1 Base de datos

Se necesitan **dos tablas**: la notificación en sí (con un `payload` genérico JSON) y una tabla pivot para los destinatarios, que además registra si la leyó.

```sql
-- Tabla principal
CREATE TABLE notifications (
    id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    guid          VARCHAR(36) UNIQUE NOT NULL,   -- UUID
    payload       JSON NOT NULL,                 -- {title, description, url, ...}
    created_by    BIGINT UNSIGNED,
    updated_by    BIGINT UNSIGNED,
    deleted_at    TIMESTAMP NULL,
    created_at    TIMESTAMP,
    updated_at    TIMESTAMP
);

-- Tabla pivot destinatarios
CREATE TABLE notification_recipients (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    read_at         TIMESTAMP NULL,              -- NULL = no leída
    UNIQUE(notification_id, user_id)
);
```

**Por qué `payload` como JSON:** permite que distintos tipos de notificaciones tengan distintos campos sin modificar el esquema. El frontend interpreta el contenido según el tipo.

---

### 1.2 Modelos

**`Notification.php`** — usa UUID como identificador externo, soft deletes y relación con usuarios:

```php
class Notification extends Model
{
    use HasUuids, SoftDeletes;

    protected $casts = ['payload' => 'array'];

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_recipients')
                    ->withPivot('read_at')
                    ->withTimestamps();
    }
}
```

**`NotificationRecipient.php`** — modelo pivot simple:

```php
class NotificationRecipient extends Model
{
    protected $fillable = ['notification_id', 'user_id', 'read_at'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
```

---

### 1.3 Evento de Broadcasting

Este es el corazón del sistema en tiempo real. El evento implementa `ShouldBroadcastNow` (disparo inmediato, sin queue):

```php
// app/Events/News.php
class News implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $guid,
        public array  $payload,
        public int    $recipientUserId,
        public bool   $isRead = false,
    ) {}

    public function broadcastOn(): array
    {
        // Canal PRIVADO — requiere autenticación del usuario
        return [
            new PrivateChannel("app.user.{$this->recipientUserId}"),
        ];
    }

    public function broadcastAs(): string
    {
        // Nombre del evento que escucha el frontend
        return 'app.event';
    }

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

**Puntos clave:**
- `ShouldBroadcastNow` vs `ShouldBroadcast`: el primero dispara en el mismo request (sin queue), el segundo encola el broadcast.
- `PrivateChannel` requiere que el frontend se autentique en el endpoint `/broadcasting/auth`.
- `broadcastAs()` define el nombre del evento que escucha el frontend con el prefijo `.` (punto).

---

### 1.4 Autorización de canal (Channel Auth)

Laravel necesita que declares quién puede suscribirse a cada canal privado:

```php
// routes/channels.php
Broadcast::channel('app.user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId;
});
```

Y exponer la ruta de autorización en `routes/api.php`:

```php
// routes/api.php
Broadcast::routes(['middleware' => ['auth:sanctum']]);
```

---

### 1.5 Servicio

El `NotificationService` centraliza toda la lógica. El método más importante es `store()`:

```php
// app/Services/NotificationService.php
public function store(array $data): Notification
{
    // $data = ['payload' => [...], 'user_ids' => [1, 2, 3]]

    $notification = Notification::create([
        'payload' => $data['payload'],
    ]);

    // syncWithoutDetaching: agrega destinatarios sin borrar los existentes
    $notification->recipients()->syncWithoutDetaching(
        collect($data['user_ids'])->mapWithKeys(fn($id) => [$id => []])
    );

    // Disparar evento para cada destinatario
    foreach ($data['user_ids'] as $userId) {
        event(new News(
            guid:            $notification->guid,
            payload:         $notification->payload,
            recipientUserId: $userId,
            isRead:          false,
        ));
    }

    return $notification;
}
```

**Marcar como leída:** actualiza sólo el pivot del usuario autenticado:

```php
public function markAsRead(string $guid, int $userId): void
{
    NotificationRecipient::where('notification_id', Notification::where('guid', $guid)->value('id'))
        ->where('user_id', $userId)
        ->update(['read_at' => now()]);
}
```

---

### 1.6 Repositorio

Filtra siempre por usuario y expone el conteo de no leídas:

```php
public function list(int $userId, array $params): array
{
    $query = Notification::whereHas('recipients', fn($q) =>
        $q->where('user_id', $userId)
    )->with(['recipients' => fn($q) =>
        $q->where('user_id', $userId)
    ]);

    // Aplicar filtros de rango de fecha, módulo, etc.
    // ...

    $paginated   = $query->orderByDesc('created_at')->paginate($params['per_page'] ?? 15);
    $unreadCount = $this->getUnreadCount($userId);

    return ['notifications' => $paginated, 'unread_count' => $unreadCount];
}

public function getUnreadCount(int $userId): int
{
    return NotificationRecipient::where('user_id', $userId)
        ->whereNull('read_at')
        ->count();
}
```

---

### 1.7 Controlador y Rutas

```php
// routes/api/notifications.php
Route::prefix('v1/notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('latest',        [NotificationController::class, 'latest']);
    Route::patch('read-all',    [NotificationController::class, 'markAllAsRead']);
    Route::patch('{guid}/read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('/', NotificationController::class)->parameters(['' => 'guid']);
});
```

---

### 1.8 Configuración del servidor WebSocket (Reverb)

**`config/broadcasting.php`:**

```php
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
],
```

**`.env` backend:**

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=mi_app_id
REVERB_APP_KEY=mi_app_key
REVERB_APP_SECRET=mi_app_secret
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
```

**Arrancar el servidor:**

```bash
php artisan reverb:start
# o con opciones
php artisan reverb:start --host=0.0.0.0 --port=8080
```

---

## PARTE 2 — Frontend

### 2.1 Dependencias

```bash
npm install laravel-echo pusher-js
```

---

### 2.2 Variables de entorno

**`.env`:**

```env
VITE_REVERB_APP_KEY=mi_app_key
VITE_REVERB_HOST=mi-backend.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REVERB_BROADCAST_AUTH=https://mi-backend.com/api/v1/broadcasting/auth
```

---

### 2.3 echoService — Conexión con Reverb

Singleton que crea y gestiona la instancia de Echo. Lo más importante es el `authorizer` personalizado que usa axios (necesario para enviar el token de auth):

```js
// src/services/echoService.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';
import {
    REVERB_APP_KEY, REVERB_HOST, REVERB_PORT,
    REVERB_SCHEME, REVERB_BROADCAST_AUTH
} from '@/config/constants';

window.Pusher = Pusher;
let echoInstance = null;

function buildAuthHeaders() {
    const token = localStorage.getItem('auth_token'); // ajustar según tu auth
    return { Authorization: `Bearer ${token}` };
}

export function getEcho() {
    if (echoInstance) return echoInstance;

    echoInstance = new Echo({
        broadcaster:       'reverb',
        key:               REVERB_APP_KEY,
        wsHost:            REVERB_HOST,
        wsPort:            REVERB_SCHEME ? 443 : REVERB_PORT,
        wssPort:           REVERB_SCHEME ? 443 : REVERB_PORT,
        forceTLS:          REVERB_SCHEME,
        enabledTransports: ['ws', 'wss'],
        // Authorizer personalizado: permite enviar headers custom al endpoint de auth
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                axios.post(REVERB_BROADCAST_AUTH, {
                    socket_id:    socketId,
                    channel_name: channel.name,
                }, {
                    headers: buildAuthHeaders(),
                })
                .then(res  => callback(false, res.data))
                .catch(err => callback(true, err));
            },
        }),
    });

    return echoInstance;
}

export function destroyEcho() {
    if (echoInstance) {
        echoInstance.disconnect();
        echoInstance = null;
    }
}
```

**Por qué el authorizer personalizado:** el `authorizer` default de Echo usa XMLHttpRequest con cookies. En apps SPA con token Bearer es necesario sobreescribirlo para enviar el `Authorization` header.

---

### 2.4 socketService — Gestión de canales

Centraliza la suscripción a canales y usa el eventBus como capa de desacoplamiento:

```js
// src/services/socketService.js
import { getEcho, destroyEcho } from './echoService';
import eventBus from './eventBusService';

class SocketService {
    connected = false;
    echo      = null;
    userId    = null;

    connect(userId) {
        if (this.connected) return;

        this.echo   = getEcho();
        this.userId = userId;

        // Canal privado del usuario — requiere auth
        this.echo
            .private(`app.user.${userId}`)
            .listen('.app.event', (data) => {
                // Re-emite al eventBus para que los componentes reaccionen
                eventBus.emit(data.event, data.payload);
            });

        this.connected = true;
    }

    disconnect() {
        if (!this.connected) return;

        if (this.echo && this.userId) {
            this.echo.leave(`app.user.${this.userId}`);
        }
        destroyEcho();

        this.connected = false;
        this.echo      = null;
        this.userId    = null;
    }
}

export default new SocketService();
```

**Nota:** `.listen('.app.event', ...)` — el punto inicial es obligatorio cuando el nombre del evento viene del método `broadcastAs()` del backend.

---

### 2.5 eventBusService — Desacoplamiento pub/sub

Un event emitter minimalista para comunicar el socketService con los componentes sin importaciones cruzadas:

```js
// src/services/eventBusService.js
class EventBusService {
    constructor() {
        this.listeners = {};
    }

    on(event, callback) {
        if (!this.listeners[event]) this.listeners[event] = [];
        this.listeners[event].push(callback);

        // Retorna función de cleanup
        return () => this.off(event, callback);
    }

    off(event, callback) {
        if (!this.listeners[event]) return;
        this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
    }

    emit(event, payload) {
        if (!this.listeners[event]) return;
        this.listeners[event].forEach(cb => cb(payload));
    }
}

export default new EventBusService();
```

---

### 2.6 useEvent composable — Integración con Vue lifecycle

Envuelve la suscripción al eventBus y se limpia automáticamente al desmontar el componente:

```js
// src/composables/useEvent.js
import { onMounted, onUnmounted } from 'vue';
import eventBus from '@/services/eventBusService';

export function useEvent(event, callback) {
    let unsubscribe;

    onMounted(() => {
        unsubscribe = eventBus.on(event, callback);
    });

    onUnmounted(() => {
        if (unsubscribe) unsubscribe();
    });
}
```

**Uso en componente:**

```js
// En cualquier componente Vue
useEvent('news', (payload) => {
    notificationStore.pushRealtime(payload);
});
```

---

### 2.7 Pinia Store

```ts
// src/store/notification.ts
import { defineStore } from 'pinia';
import * as api from '@/api/notifications';

export const useNotificationStore = defineStore('notification', {
    state: () => ({
        unreadCount: 0,
        list:        [],
        latest:      [],
        pagination:  { currentPage: 1, lastPage: 1, perPage: 15, total: 0 },
        filters:     { range: null, module: null, module_id: null },
        loading:     false,
    }),

    actions: {
        async fetch() {
            this.loading = true;
            const res = await api.index(this.filters);
            this.list        = res.data.notifications.data;
            this.unreadCount = res.data.unread_count;
            this.pagination  = {
                currentPage: res.data.notifications.current_page,
                lastPage:    res.data.notifications.last_page,
                perPage:     res.data.notifications.per_page,
                total:       res.data.notifications.total,
            };
            this.loading = false;
        },

        async fetchLatest() {
            const res    = await api.latest();
            this.latest      = res.data.notifications;
            this.unreadCount = res.data.unread_count;
        },

        async markAsRead(guid: string) {
            await api.markAsRead(guid);
            // Actualizar localmente sin refetch
            const item = this.list.find(n => n.guid === guid);
            if (item) item.read_at = new Date().toISOString();
            const latestItem = this.latest.find(n => n.guid === guid);
            if (latestItem && !latestItem.read_at) {
                latestItem.read_at = new Date().toISOString();
                this.unreadCount   = Math.max(0, this.unreadCount - 1);
            }
        },

        // Inserta notificación en tiempo real desde WebSocket
        pushRealtime(notification: any) {
            // Evitar duplicados
            if (this.latest.some(n => n.guid === notification.guid)) return;

            this.latest.unshift(notification);
            if (!notification.is_read) this.unreadCount++;
        },
    },
});
```

---

### 2.8 Ciclo de vida en App.vue

Conectar/desconectar el WebSocket en función del estado de autenticación:

```vue
<!-- src/App.vue -->
<script setup>
import { watch, onMounted } from 'vue';
import { useAuthStore } from '@/store/auth';
import socketService from '@/services/socketService';

const authStore = useAuthStore();

onMounted(() => {
    if (authStore.isAuthenticated && authStore.user?.id) {
        socketService.connect(authStore.user.id);
    }
});

// Reconectar si cambia el usuario, desconectar si hace logout
watch(
    () => [authStore.isAuthenticated, authStore.user?.id],
    ([isAuth, userId]) => {
        if (isAuth && userId) {
            socketService.connect(userId);
        } else {
            socketService.disconnect();
        }
    }
);
</script>
```

---

### 2.9 Componente de ejemplo — Campana en TopBar

```vue
<script setup>
import { onMounted } from 'vue';
import { useNotificationStore } from '@/store/notification';
import { useEvent } from '@/composables/useEvent';

const store = useNotificationStore();

onMounted(() => store.fetchLatest());

// Cuando llega un evento WebSocket de tipo 'news'
useEvent('news', (payload) => {
    store.pushRealtime(payload.data ?? payload);
});
</script>

<template>
  <div class="relative">
    <button @click="open = !open">
      <BellIcon />
      <span v-if="store.unreadCount > 0" class="badge">
        {{ store.unreadCount }}
      </span>
    </button>

    <div v-if="open" class="dropdown">
      <div v-for="n in store.latest" :key="n.guid"
           :class="{ 'unread': !n.read_at }"
           @click="store.markAsRead(n.guid)">
        <p>{{ n.payload?.title }}</p>
        <p>{{ n.payload?.description }}</p>
      </div>
    </div>
  </div>
</template>
```

---

## PARTE 3 — Cómo disparar notificaciones desde cualquier parte del backend

Inyectar `NotificationService` y llamar `store()` con el payload que necesites:

```php
// Desde cualquier Service, Job o Controller
$this->notificationService->store([
    'payload'  => [
        'title'       => 'Operación por vencer',
        'description' => "La operación #{$operation->number} vence en 24 horas.",
        'url'         => "/operations/{$operation->guid}", // navegación en el frontend
        'type'        => 'warning',                       // para estilos en UI
    ],
    'user_ids' => $recipientIds, // array de IDs de usuarios destinatarios
]);
```

El payload JSON es completamente libre; puedes agregar cualquier campo que el frontend necesite interpretar.

---

## PARTE 4 — Guía de implementación en otro proyecto

### Checklist backend

```
[ ] 1. Instalar Reverb:         composer require laravel/reverb
[ ] 2. Publicar config:         php artisan reverb:install
[ ] 3. Crear migraciones:       notifications + notification_recipients
[ ] 4. Crear modelos:           Notification, NotificationRecipient
[ ] 5. Crear evento:            app/Events/News.php (ShouldBroadcastNow, PrivateChannel)
[ ] 6. Definir auth de canal:   routes/channels.php
[ ] 7. Exponer Broadcast::routes() en routes/api.php
[ ] 8. Crear NotificationService con store(), markAsRead(), latest()
[ ] 9. Crear NotificationController con index, show, markAsRead, latest
[ ] 10. Agregar rutas en:       routes/api/
[ ] 11. Configurar .env con REVERB_APP_* y BROADCAST_CONNECTION=reverb
```

### Checklist frontend

```
[ ] 1. Instalar deps:       npm install laravel-echo pusher-js
[ ] 2. Variables de entorno: VITE_REVERB_* en .env
[ ] 3. Crear echoService.js con authorizer custom (si usas token Bearer)
[ ] 4. Crear socketService.js que suscriba al canal privado y emita al eventBus
[ ] 5. Crear eventBusService.js (pub/sub desacoplado)
[ ] 6. Crear useEvent.js composable (auto-cleanup en onUnmounted)
[ ] 7. Crear notificationStore con pushRealtime() y markAsRead()
[ ] 8. Conectar socketService en App.vue watching el estado de auth
[ ] 9. En componentes que reaccionen: useEvent('news', handler)
```

### Decisiones de diseño que conviene mantener

| Decisión | Por qué |
|---|---|
| `payload` JSON en lugar de columnas fijas | Permite distintos tipos de notificación sin cambiar el schema |
| Tabla pivot con `read_at` | Cada usuario tiene su propio estado de lectura |
| `ShouldBroadcastNow` | Disparo inmediato; usar `ShouldBroadcast` si quieres queue |
| EventBus en frontend | Desacopla el socket del componente; cualquier componente puede reaccionar sin saber del socket |
| `authorizer` custom en Echo | Necesario con autenticación Bearer token (SPAs) |
| Singleton en echoService | Una sola conexión WebSocket por sesión |
| Reconectar en watch de authState | Gestión limpia de auth: si hace logout cierra el socket |

---

## PARTE 5 — Diagrama de flujo completo

```
BACKEND                              FRONTEND
────────────────────────────────     ─────────────────────────────────────
                                     App.vue (onMounted/watch)
                                       └─► socketService.connect(userId)
                                              └─► getEcho() → new Echo({...})
                                                     └─► se suscribe al canal
                                                         private app.user.{id}

NotificationService::store()
  │
  ├─► Notification::create({payload})
  ├─► recipients()->syncWithoutDetaching([userId => []])
  └─► event(new News(guid, payload, userId))
           │
           ▼
      Laravel Reverb
      canal: private app.user.{userId}
      evento: .app.event
      payload: {event:'news', payload:{guid,data,user_id,is_read}}
           │
           ▼                          Echo recibe .app.event
                                       └─► eventBus.emit('news', payload)
                                              │
                              ┌───────────────┴──────────────────┐
                              ▼                                    ▼
                    AppTopbar (useEvent)              NotificationsTab (useEvent)
                    pushRealtime(payload)             fetch() → refetch list
                    unreadCount++
                    nueva notif en dropdown
```

---

## Paths de referencia en este proyecto

### Backend (`alphinance-back/`)

| Archivo | Rol |
|---|---|
| `app/Models/Notification.php` | Modelo principal |
| `app/Models/NotificationRecipient.php` | Pivot destinatarios |
| `app/Events/News.php` | Evento de broadcast |
| `app/Services/NotificationService.php` | Lógica de negocio |
| `app/Services/OperationExpirationNotificationService.php` | Notif. de vencimiento de operaciones |
| `app/Repositories/NotificationRepositoryEloquent.php` | Acceso a datos |
| `app/Http/Controllers/V1/NotificationController.php` | Controlador HTTP |
| `routes/api/notifications.php` | Rutas |
| `config/broadcasting.php` | Configuración Reverb |

### Frontend (`alphinance-front/src/`)

| Archivo | Rol |
|---|---|
| `services/echoService.js` | Conexión Echo/Reverb (singleton) |
| `services/socketService.js` | Gestión de canales WebSocket |
| `services/eventBusService.js` | Pub/sub desacoplado |
| `composables/useEvent.js` | Hook Vue para suscribirse al eventBus |
| `store/notification.ts` | Estado global Pinia |
| `api/notifications.ts` | Llamadas HTTP a la API |
| `api/types/notifications.ts` | Tipos TypeScript |
| `views/notifications/Notifications.vue` | Vista principal |
| `views/notifications/NotificationsTab.vue` | Tab de novedades con filtros |
| `views/notifications/NotificationRequestsTab.vue` | Tab de solicitudes de aprobación |
