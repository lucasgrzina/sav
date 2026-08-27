# Plan técnico: Web Push Notifications (backend)

## Input procesado
Brief informal del usuario (requerimiento cerrado por el equipo mobile — PWA). No hay spec funcional ni ticket previo para esto.

## Resumen ejecutivo
Se agrega el canal `push` al motor de notificaciones ya existente (`app/Notifications/*`), que hoy solo despacha WhatsApp/Email. El trabajo tiene dos mitades: (1) un módulo CRUD estándar `PushSubscription` con los dos endpoints self-service que pide mobile (`POST`/`DELETE /api/v1/push/subscriptions`), y (2) completar el `Channel::Push` que **ya existe en el enum pero no está cableado a nada** — falta el gateway, el content builder, la config VAPID y, sobre todo, que alguien cree `AlertRecipient` con `channel = push`. Sin esta segunda mitad, los endpoints de suscripción no tienen ningún efecto: nada dispara un push jamás.

## Decisiones tomadas

DEC-01 — Prefijo de ruta `v1`, no el path literal del brief — CONFIRMADO, sin cambios
  Decisión: los endpoints van en `/api/v1/push/subscriptions`, no `/api/push/subscriptions` como está escrito literalmente en el contrato de mobile.
  Justificación: `backend-conventions.md` es explícito y no negociable — "Prefijo `v1`, middleware `auth:sanctum`" — y las 21 rutas existentes en `back/routes/api/*.php` sin excepción usan `v1/`. Es mucho más barato que mobile anteponga `/v1/` a su base URL que romper la convención de versionado para un solo endpoint.
  Alternativa descartada: respetar el path literal — generaría el único endpoint sin versionar de todo el backend, deuda técnica para siempre.
  **Confirmado con mobile (`PUSH_PLAN_REVIEW.md`, sección B1):** no era un desvío real del contrato. Su `APP_API_ENDPOINT` ya termina en `/api/v1` y `PushApiService` llama a `/push/subscriptions` sin prefijo — la URL que sale del dispositivo en producción ya es `/api/v1/push/subscriptions`. El path sin `v1` del brief era documentación desactualizada de su lado (bug propio, corregido el 2026-08-21, cubierto por `ApiRequestHeadersTest`). Cero cambio de nuestro lado — mergear tranquilos.

DEC-02 — `cloud_id` en la respuesta del POST **es** literalmente nuestro `guid`
  Decisión: la columna interna sigue siendo `guid` (regla dura #6). El `PushSubscriptionResource` lo renombra a `cloud_id` únicamente en el shape de esta respuesta puntual, porque así lo pidió mobile.
  Justificación: no es un concepto nuevo — es el mismo identificador server-side que usa todo el resto de la API, con otro nombre de campo en este contrato externo específico.
  Alternativa descartada: agregar una columna `cloud_id` separada — redundante, dos IDs para lo mismo, riesgo de desincronización.

DEC-03 — Sin permisos Spatie nuevos
  Decisión: los dos endpoints solo llevan `auth:sanctum`, sin `push.alta`/`push.baja` ni gate alguno.
  Justificación: es un recurso self-service sobre el propio dispositivo del usuario autenticado (`$request->user()`), exactamente como `/v1/user/settings` (`UserSettingController`) y `/v1/notifications` (`NotificationController`), ninguno de los cuales tiene permiso Spatie asociado — confirmado también en `PermissionSeeder` (cero entradas `notifications.*`). Un permiso de módulo no tiene sentido para "administrar mi propia suscripción del navegador".
  Alternativa descartada: agregar `push.alta`/`push.baja` — inconsistente con el precedente de recursos self-service del propio proyecto.

DEC-04 — `endpoint` no lleva índice único directo; se agrega `endpoint_hash`
  Decisión: la columna `endpoint` es `text` (URLs de FCM/Mozilla pueden superar 255 y hasta 2KB). La clave natural para upsert/unicidad es `endpoint_hash` = `sha256(endpoint)`, `char(64)`, con índice único.
  Justificación: ni SQLite (usado en tests) ni MySQL (target de producción, a confirmar) soportan un índice único sano sobre un `TEXT`/`VARCHAR` largo sin prefijo — MySQL InnoDB tiene límite de 3072 bytes de key length, y con utf8mb4 un `VARCHAR(2048)` ya lo excede. Hashear es el patrón estándar para "clave natural larga + necesito unicidad indexada".
  Alternativa descartada: `string(191)->unique()` truncando el endpoint — corrompería URLs reales y generaría colisiones falsas.

DEC-05 — `PushSubscription` pertenece a `User`, no a `UserProfile`
  Decisión: FK `user_id → users.id`, no `user_profile_id → user_profiles.id`.
  Justificación: un dispositivo/navegador se suscribe una sola vez a nivel de browser, independientemente de a cuántos vets (tenants) esté vinculado ese usuario vía distintos `UserProfile` (ver `User::profiles(): HasMany`). Es una propiedad del dispositivo físico, no del rol-por-tenant. `AlertRecipient.user_profile_id` sigue intacto (sigue siendo el nivel correcto para WhatsApp/Email, que sí son contactos por perfil); el cruce hacia Push se hace bajando un nivel más: `AlertRecipient.userProfile.user_id → PushSubscription.user_id`.
  Alternativa descartada: `user_profile_id` — obligaría a duplicar la misma suscripción de browser por cada tenant al que pertenece el usuario, sin ningún beneficio.

DEC-06 — Reasignación de `endpoint` a otro usuario en upsert (dispositivo compartido)
  Decisión: si en el `POST` el `endpoint` ya existe pero pertenece a otro `user_id`, se reasigna al usuario autenticado actual (se pisa el owner), en lugar de rechazar la request.
  Justificación: el navegador reutiliza el mismo `endpoint` de Push API mientras no se revoque el permiso — si el Usuario A cierra sesión y el Usuario B inicia sesión en el mismo dispositivo/navegador y activa notificaciones, el browser puede devolver el mismo `endpoint`. El usuario autenticado en el momento del `POST` es la fuente de verdad de "quién es dueño de este dispositivo ahora".
  Alternativa descartada: rechazar con 409 — dejaría al segundo usuario sin poder activar notificaciones en su propio dispositivo, mala UX sin beneficio de seguridad real (el endpoint no es secreto).

DEC-07 — `DELETE` scopeado al usuario autenticado, 404 idéntico en ambos casos "no existe" / "es de otro usuario"
  Decisión: el borrado busca por `endpoint_hash + user_id = auth user`. Si no matchea (porque no existe o porque es de otro usuario), responde 404 sin distinguir el motivo.
  Justificación: mobile ya especificó que 204 y 404 son ambos éxito para el cliente (idempotente), así que no hay necesidad funcional de diferenciar; y no diferenciar evita que un usuario autenticado pueda usar el endpoint para sondear si un `endpoint` ajeno existe en la base (mínima fuga de información, gratis).
  Alternativa descartada: borrar por `endpoint_hash` sin filtrar por usuario — permitiría a cualquier usuario autenticado borrar la suscripción de otro si de alguna forma conoce/adivina su `endpoint`.
  **Confirmado con mobile (`PUSH_PLAN_REVIEW.md`, sección B2):** es exactamente el comportamiento que esperan — su cliente trata cualquier respuesta que no sea 404 como éxito y ni siquiera parsea el body en el caso 404 (`if ($response->status() !== 404) { ... }`). Sin cambios.

DEC-08 — Push se agrega como canal **paralelo** a WhatsApp, gateado por suscripción activa (revisada tras review de mobile)
  Decisión original: cada vez que se crea un `AlertRecipient` de `channel = Channel::Whatsapp` para un destinatario resuelto por `roles`, se crea también uno con `channel = Channel::Push`, sin condición — y quedaba marcada como pendiente de confirmar con producto por el riesgo de duplicar volumen.
  **Corrección aplicada (`PUSH_PLAN_REVIEW.md`, sección C2) — reemplaza la decisión original, ya no requiere decisión de producto:** el volumen no era el problema real. Los destinatarios de una alerta se resuelven por los `roles` del protocolo (encargado de campo, dueño) y la mayoría de ellos nunca instala la PWA. Sin gating, cada alerta generaría N `AlertRecipient` de canal Push que fallan **para siempre** con `RecipientContactNotFoundException`, envenenando las métricas de entrega y enterrando el `Log::critical` de DEC-11 entre miles de warnings normales de "no tiene la app".
  Decisión final: en `AlertRecipientFactory::createForManagers()`, el canal `Push` solo se agrega si el `UserProfile` tiene una suscripción push activa (`hasActiveSubscription($manager)`); si no la tiene, se salta ese canal para ese destinatario en silencio — no se crea la fila, no se marca `Failed`. "No tiene la app instalada" deja de ser un fallo de entrega y pasa a ser "el canal no aplica". Con este filtro, push va en TODAS las alertas (no hace falta decidir por tipo de alerta), pero solo llega a quien puede recibirlo — no se toca `config('notifications.fallback')`, sigue sin ser un fallback de WhatsApp.
  Alternativa descartada: Push como entrada de `notifications.fallback['whatsapp']` — semánticamente incorrecto (fallback es "cuando el otro canal falló", no "además"). También se descartó dejarlo sin gating a la espera de una decisión de producto — el review dejó claro que el gating es una corrección técnica, no una decisión de alcance de negocio.
  Nota: `AlertRecipient::toDto()` / `toPushDto()` conserva su chequeo de suscripción y su `RecipientContactNotFoundException` (ver más abajo) como red de seguridad secundaria — cubre el caso borde de que el usuario revoque el permiso push entre el momento en que se creó el `AlertRecipient` y el momento del despacho real. Ya no es el mecanismo principal de filtrado, ese rol pasa a `AlertRecipientFactory`.

DEC-09 — Fan-out de canales centralizado en `AlertRecipientFactory`, no duplicado en los 4 call sites
  Decisión: se extrae un servicio nuevo `App\Notifications\Services\AlertRecipientFactory::createForManagers(Alert, iterable $managers): Collection` que reemplaza el `foreach` inline hardcodeado a `Channel::Whatsapp` en `GenerateProgramTaskDueAlertsListener`, `ScheduleProgramCreatedAlertListener`, `HandleProgramCancelledListener` y `ProgramShareService::sendPdfToRecipients`. La lista de canales sale de `config('notifications.default_channels')`.
  Justificación: agregar Push tocando 4 archivos casi idénticos a mano es exactamente el tipo de duplicación que después se desincroniza (alguien agrega un canal nuevo y se olvida uno de los 4 lugares). Centralizarlo también hace que agregar/sacar un canal sea un cambio de config, no de código.
  Alternativa descartada: repetir el mismo `foreach` con dos `AlertRecipient::create()` en cada uno de los 4 sitios — funciona, pero es la deuda técnica que ya se puede evitar en el mismo PR.

DEC-10 — El `WebPushGateway` resuelve las suscripciones del usuario directo por Eloquent, no vía `PushSubscriptionRepositoryInterface`
  Decisión: `App\Notifications\*` sigue consultando modelos Eloquent directamente (como ya hace `AlertRecipient::toDto()` con `Contact`), sin pasar por la capa `App\Contracts\Repositories`.
  Justificación: es el patrón ya establecido en este bounded context — `App\Notifications` es semi-independiente del resto de `app/` y no usa la capa de repositorios de `App\Repositories`/`App\Contracts\Repositories` en ningún punto existente. El repository pattern completo (interface + Eloquent + bind) se reserva para el módulo CRUD self-service (`PushSubscriptionController`), que sí vive en el árbol principal de `App\Http`/`App\Services`.
  Alternativa descartada: forzar una interface de repositorio también para el lookup desde el gateway — rompe la consistencia interna de `App\Notifications`, que ya no usa ese patrón en ningún gateway existente.

DEC-11 — VAPID mismatch (403 `VapidPkHashMismatch`) se trata como falla definitiva por suscripción, con `Log::critical` explícito
  Decisión: el `WebPushGateway` no reintenta ni lanza excepción transitoria ante un 403 con `VapidPkHashMismatch` en el body — lo trata como `DeliveryResult::failed(...)` (falla de negocio, no de infraestructura), pero además emite `Log::critical` con un mensaje explícito de que el problema es de configuración global (par de llaves VAPID equivocado) y no se arregla reintentando.
  Justificación: el usuario documentó explícitamente que este es un fallo sin arreglo posible del lado servidor una vez que ocurre — reintentar con backoff (5 intentos, hasta 30 min) es tiempo desperdiciado porque el error es determinístico por suscripción. El `Log::critical` (en vez de `warning`/`error`) es deliberado: si aparece en TODAS las suscripciones a la vez es señal de que rotaron las llaves VAPID en `.env` sin coordinarlo con mobile, y hace falta que un operador lo vea y purgue+re-suscriba, no que quede enterrado en logs de nivel `error` junto con fallos individuales normales de contacto faltante.
  Riesgo residual documentado más abajo en "Riesgos".
  **Confirmado con mobile (`PUSH_PLAN_REVIEW.md`, sección C1):** coinciden en que el `Log::critical` es la decisión correcta y en que la única mitigación real es de proceso (rotar = purgar `push_subscriptions` + re-suscribir cada dispositivo a mano). Ver DEC-12 más abajo sobre quién genera el par de llaves.

DEC-12 (nueva) — Las llaves VAPID las genera la nube, no se reciben de mobile
  Decisión: el par de llaves VAPID (`APP_VAPID_PUBLIC_KEY` / `APP_VAPID_PRIVATE_KEY`) lo genera este backend, no se recibe del equipo mobile como decía la versión original de este plan.
  Justificación (`PUSH_PLAN_REVIEW.md`, sección C1): la nube es quien firma los envíos — la llave privada no debería salir nunca del proceso que despacha, y menos viajar por mail o Slack entre dos equipos. A mobile solo hay que pasarle la **pública** (no es secreta, se expone al browser igual) por cada ambiente (staging/producción si son distintos).
  Alternativa descartada: recibir el par de manos de mobile (lo que decía el plan original) — obliga a que la llave privada circule fuera del sistema que la usa, sin ningún beneficio.
  Acción concreta: generar el par con `php artisan webpush:vapid` (si el paquete `minishlink/web-push` trae ese comando) o con la CLI estándar de `web-push`/`openssl` equivalente, cargarlo en `.env` de cada ambiente, y pasarle la pública a mobile fuera de banda una sola vez.

## Cambios en BACKEND

### Módulo self-service: `PushSubscription`

#### `back/database/migrations/2026_08_25_000001_create_push_subscriptions_table.php`
```php
Schema::create('push_subscriptions', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('device_uuid', 36)->nullable()
          ->comment('uuid de la SUSCRIPCION (lo que manda mobile como "uuid" en el POST), no del dispositivo — informativo, no es clave de unicidad. Ver D3 en Riesgos.');
    $table->text('endpoint');
    $table->char('endpoint_hash', 64)->unique()
          ->comment('sha256(endpoint) — clave natural real para upsert/unicidad; endpoint es TEXT y no puede indexarse directo');
    $table->string('p256dh');
    $table->string('auth_key')->comment('Push API auth secret (keys.auth) — renombrado para no chocar con el término "auth" del framework');
    $table->string('content_encoding')->default('aes128gcm');
    $table->string('device_label')->nullable();
    $table->timestamp('device_updated_at')->nullable()
          ->comment('updated_at reportado por el cliente al crear/tocar la suscripción — distinto del updated_at propio de la fila');
    $table->timestamps();

    $table->index('user_id');
});
```
Sin `softDeletes()` (convención del proyecto). Sin FK a `user_profiles` — ver DEC-05.

#### `back/app/Models/PushSubscription.php`
```php
class PushSubscription extends Model
{
    use HasGuid;

    protected $fillable = [
        'user_id', 'device_uuid', 'endpoint', 'endpoint_hash',
        'p256dh', 'auth_key', 'content_encoding', 'device_label', 'device_updated_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['device_updated_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
```

#### `back/app/Contracts/Repositories/PushSubscriptionRepositoryInterface.php`
```php
interface PushSubscriptionRepositoryInterface
{
    public function findByEndpointHash(string $endpointHash): ?PushSubscription;
    public function findByEndpointHashForUser(string $endpointHash, int $userId): ?PushSubscription;
    public function create(array $data): PushSubscription;
    public function update(Model $subscription, array $data): PushSubscription;
    public function destroy(Model $subscription): bool|null;
}
```

#### `back/app/Repositories/PushSubscriptionRepositoryEloquent.php`
Extiende `BaseRepositoryEloquent` (ya trae `create`/`update`/`destroy`/`findByGuid` genéricos — igual que `ContactRepositoryEloquent`). Solo agrega:
```php
protected function model(): string { return PushSubscription::class; }

public function findByEndpointHash(string $endpointHash): ?PushSubscription
{
    return $this->newQuery()->where('endpoint_hash', $endpointHash)->first();
}

public function findByEndpointHashForUser(string $endpointHash, int $userId): ?PushSubscription
{
    return $this->newQuery()
        ->where('endpoint_hash', $endpointHash)
        ->where('user_id', $userId)
        ->first();
}
```

#### `back/app/Providers/AppServiceProvider.php` — modificar
Agregar al `register()`:
```php
$this->app->bind(PushSubscriptionRepositoryInterface::class, PushSubscriptionRepositoryEloquent::class);
```
(+ los dos `use` correspondientes)

#### `back/app/Http/Requests/StorePushSubscriptionRequest.php`
```php
public function authorize(): bool { return true; }

public function rules(): array
{
    return [
        'uuid' => ['nullable', 'string', 'max:255'],
        'endpoint' => ['required', 'string', 'max:8192', 'url'],
        'keys' => ['required', 'array'],
        'keys.p256dh' => ['required', 'string'],
        'keys.auth' => ['required', 'string'],
        'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        'device_label' => ['nullable', 'string', 'max:255'],
        'updated_at' => ['nullable', 'date'],
        'user_id' => ['prohibited'], // regla dura del brief: nunca aceptar suplantación de usuario
    ];
}

public function messages(): array
{
    return [
        'endpoint.required' => 'El endpoint de la suscripción es obligatorio.',
        'endpoint.url' => 'El endpoint debe ser una URL válida.',
        'keys.p256dh.required' => 'Falta la clave p256dh de la suscripción.',
        'keys.auth.required' => 'Falta la clave auth de la suscripción.',
        'user_id.prohibited' => 'No se permite especificar el usuario de la suscripción.',
    ];
}
```

#### `back/app/Http/Requests/DeletePushSubscriptionRequest.php`
```php
public function authorize(): bool { return true; }

public function rules(): array
{
    return ['endpoint' => ['required', 'string']];
}

public function messages(): array
{
    return ['endpoint.required' => 'El endpoint es obligatorio para eliminar la suscripción.'];
}
```

#### `back/app/Http/Resources/V1/PushSubscriptionResource.php`
```php
class PushSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // DEC-02: cloud_id ES el guid estándar del proyecto, renombrado solo en este
        // contrato externo puntual que pidió mobile. No es un identificador nuevo.
        return ['cloud_id' => $this->guid];
    }
}
```

#### `back/app/Services/PushSubscriptionService.php`
```php
class PushSubscriptionService
{
    public function __construct(private PushSubscriptionRepositoryInterface $repository) {}

    public function subscribe(User $user, array $data): PushSubscription
    {
        $endpointHash = hash('sha256', $data['endpoint']);
        $existing = $this->repository->findByEndpointHash($endpointHash);

        $payload = [
            'user_id' => $user->id, // DEC-06: siempre el usuario autenticado, pisa el owner previo
            'device_uuid' => $data['uuid'] ?? null,
            'endpoint' => $data['endpoint'],
            'endpoint_hash' => $endpointHash,
            'p256dh' => $data['keys']['p256dh'],
            'auth_key' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'device_label' => $data['device_label'] ?? null,
            'device_updated_at' => $data['updated_at'] ?? null,
        ];

        return $existing === null
            ? $this->repository->create($payload)
            : $this->repository->update($existing, $payload);
    }

    public function unsubscribe(User $user, string $endpoint): bool
    {
        $subscription = $this->repository->findByEndpointHashForUser(
            hash('sha256', $endpoint),
            $user->id,
        );

        return $subscription !== null && (bool) $this->repository->destroy($subscription);
    }
}
```
Sin `DB::transaction()` — escritura de una sola tabla (convención: transacción solo si toca múltiples tablas).

#### `back/app/Http/Controllers/V1/PushSubscriptionController.php`
```php
class PushSubscriptionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private PushSubscriptionService $service) {}

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        try {
            $subscription = $this->service->subscribe($request->user(), $request->validated());
            return $this->makeSuccess(new PushSubscriptionResource($subscription));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        try {
            $deleted = $this->service->unsubscribe($request->user(), $request->validated('endpoint'));
            return $deleted
                ? response()->json(null, 204)
                : $this->makeNotFound('La suscripción no existe o ya fue eliminada.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

### Rutas API

#### `back/routes/api/push-subscriptions.php` (nuevo)
```php
Route::prefix('v1/push')->middleware('auth:sanctum')->group(function () {
    Route::post('/subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/subscriptions', [PushSubscriptionController::class, 'destroy']);
});
```
Se incluye automáticamente vía el `glob(__DIR__.'/api/*.php')` de `back/routes/api.php` — no requiere tocar ese archivo.

### Permisos Spatie
Ninguno nuevo — ver DEC-03.

### Contrato de los endpoints

**POST /api/v1/push/subscriptions**
- Auth: `auth:sanctum` (Bearer)
- Request: shape exacto del brief (`uuid`, `endpoint`, `keys.p256dh`, `keys.auth`, `content_encoding`, `device_label`, `updated_at`)
- Response 200: `{ "success": true, "data": { "cloud_id": "<guid>" } }` — el wrapper `{success,data}` lo agrega `ApiResponseTrait`/`ResponseHelper`, ya estándar en todo el backend. **Confirmado con mobile (`PUSH_PLAN_REVIEW.md`, sección F):** no se hace ninguna excepción de formato para este endpoint — su documentación vieja esperaba una respuesta plana (`{"cloud_id": "42"}`) y ya ajustaron su parser para leer `data.cloud_id` del envelope estándar.
- 401: sin token válido
- 422: `endpoint`/`keys.p256dh`/`keys.auth` faltantes, o `user_id` presente en el body

**DELETE /api/v1/push/subscriptions**
- Auth: `auth:sanctum`
- Request: `{ "endpoint": "..." }`
- Response 204: sin body
- Response 404: `{ "success": false, ... }` vía `makeNotFound` — mobile ya trata esto como éxito según el brief, no requiere shape especial
- 401: sin token válido

### Tests a generar (self-service module)
- Feature `PushSubscriptionControllerTest`:
  - POST crea una suscripción nueva → 200, `data.cloud_id` es un GUID válido, fila en `push_subscriptions` con `user_id` del usuario autenticado.
  - POST con el mismo `endpoint` dos veces → upsert, sigue habiendo una sola fila, mismo `cloud_id` en ambas respuestas.
  - POST con `endpoint` ya asociado a OTRO usuario → reasigna `user_id` (DEC-06), verificar que la fila queda con el nuevo `user_id`.
  - POST con `user_id` en el body → 422 (verificar que NO se usa ese valor aunque pasara validación por error).
  - POST sin token → 401.
  - POST sin `endpoint`/`keys.p256dh`/`keys.auth` → 422 con mensajes en español.
  - DELETE de una suscripción propia existente → 204, fila eliminada.
  - DELETE de una suscripción inexistente → 404.
  - DELETE de una suscripción que pertenece a OTRO usuario → 404 (no 403, ver DEC-07) y la fila del otro usuario sigue existiendo intacta.
  - DELETE sin token → 401.
  - Todas las respuestas: `assertJsonMissing(['id'])`.

## Cambios en la dispatch pipeline (`App\Notifications\*`)

### Archivos a crear

#### `back/app/Notifications/Data/PushContent.php`
**Actualizado tras review de mobile (`PUSH_PLAN_REVIEW.md`, sección A) — el service worker ya está en producción (`public/service-worker.js`) y espera estos campos exactos; sin ellos las notificaciones llegan, pero rotas (duplicadas, sin persistir, apuntando al home).**
```php
final readonly class PushContent implements MessageContent
{
    /**
     * @param string $tag colapsa reenvíos de la MISMA alerta en una sola notificación del SO
     *        (el SW hace `tag: payload.tag` + `renotify: Boolean(payload.tag)`) — usar
     *        siempre "alert-{guid_de_la_alerta}", nunca un id numérico (A1).
     * @param string|null $url destino del click, RELATIVO a la raíz de la app y SIN barra
     *        inicial (ej. "programas/{guid}") — la PWA no vive en la raíz del dominio
     *        (se sirve desde /mobile), así que un url absoluto o con "/" inicial rompe el
     *        deep link (A3). Nunca un id numérico.
     * @param array<string,mixed> $data payload adicional que viaja intacto al click del SW;
     *        debe incluir 'requires_confirmation' (bool, snake_case plural — así lo lee el
     *        SW) mapeado desde `alerts.require_confirmation` (A2). Todo lo demás en $data:
     *        siempre guids, nunca ids numéricos.
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $tag,
        public ?string $url = null,
        public array $data = [],
    ) {}
}
```
Contrato final del payload que arma el gateway (JSON exacto que espera el service worker, ver `WebPushGateway` más abajo):
```json
{
  "title": "Brucelosis — La Esperanza",
  "body": "Mañana: revacunar terneras de 3 a 8 meses",
  "tag": "alert-9f1c8b2a-...",
  "url": "programas/d749d12f-...",
  "data": {
    "requires_confirmation": true,
    "alert_guid": "9f1c8b2a-...",
    "program_guid": "d749d12f-..."
  }
}
```

#### `back/app/Notifications/Services/AlertRecipientFactory.php`
**Actualizado tras review de mobile (`PUSH_PLAN_REVIEW.md`, sección C2) — ver DEC-08 revisada.**
```php
final class AlertRecipientFactory
{
    /**
     * @param iterable<UserProfile> $managers
     * @return Collection<int, AlertRecipient>
     */
    public function createForManagers(Alert $alert, iterable $managers): Collection
    {
        $channels = collect(config('notifications.default_channels', [Channel::Whatsapp]));
        $created = collect();

        foreach ($managers as $manager) {
            foreach ($channels as $channel) {
                // DEC-08: Push solo aplica si el perfil tiene una suscripción activa.
                // "No tiene la app instalada" no es un fallo de entrega — es un canal que
                // no corresponde. Sin este filtro, la mayoría de los destinatarios (son
                // veterinarios de campo resueltos por roles, no usuarios de la PWA)
                // generarían un AlertRecipient Push fallido en cada alerta, para siempre.
                if ($channel === Channel::Push && ! $this->hasActiveSubscription($manager)) {
                    continue;
                }

                $created->push(AlertRecipient::create([
                    'alert_id' => $alert->id,
                    'user_profile_id' => $manager->id,
                    'channel' => $channel,
                    'status' => DeliveryStatus::Pending,
                    'idempotency_key' => Str::uuid()->toString(),
                ]));
            }
        }

        return $created;
    }

    private function hasActiveSubscription(UserProfile $manager): bool
    {
        return PushSubscription::where('user_id', $manager->user_id)->exists();
    }
}
```
No requiere binding en ningún ServiceProvider — clase concreta sin dependencias, Laravel la resuelve por auto-wiring.

#### `back/app/Notifications/Gateways/WebPush/WebPushGateway.php`
```php
final class WebPushGateway implements NotificationChannelGateway
{
    /** Límite real de un push (~4KB de payload total, ver D2 del review de mobile). Se
     *  recorta $body para dejar margen a title/tag/url/data y no superar el límite del
     *  navegador — el detalle completo se ve al abrir la app, no hace falta en la notif. */
    private const BODY_MAX_LENGTH = 500;

    public function __construct(private readonly \Minishlink\WebPush\WebPush $client) {}

    public function channel(): Channel { return Channel::Push; }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $subscriptions = PushSubscription::where('user_id', $message->recipient->userId)->get();

        if ($subscriptions->isEmpty()) {
            return DeliveryResult::failed('sin_suscripciones_activas');
        }

        /** @var PushContent $content */
        $content = $message->content;
        $payload = json_encode([
            'title' => $content->title,
            'body' => Str::limit($content->body, self::BODY_MAX_LENGTH),
            'tag' => $content->tag,
            'url' => $content->url,
            'data' => $content->data,
        ]);

        foreach ($subscriptions as $subscription) {
            $this->client->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth_key,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                $payload,
            );
        }

        // D1 (review de mobile): indexar por endpoint ANTES del flush. Con firstWhere() sobre
        // la Collection original, si el match fallaba (ej. la API real de minishlink no
        // expone el endpoint tal cual se asume acá) $subscription quedaba null en silencio y
        // la rama de 404/410 más abajo nunca borraba la fila — la suscripción muerta volvía a
        // fallar en cada alerta futura, exactamente lo que esa rama busca evitar.
        $subscriptionsByEndpoint = $subscriptions->keyBy('endpoint');

        $sentAny = false;
        $allTransient = true;
        $lastReason = 'push_delivery_failed';

        foreach ($this->client->flush() as $report) {
            $subscription = $subscriptionsByEndpoint->get((string) $report->getRequest()->getUri());

            if ($report->isSuccess()) {
                $sentAny = true;
                $allTransient = false;
                continue;
            }

            $status = $report->getResponse()?->getStatusCode();
            $reason = $report->getReason();
            $lastReason = $reason;

            // 404/410: suscripción caducada del lado del navegador — no hay nada que reintentar,
            // y dejarla viva solo generaría el mismo fallo en cada alerta futura.
            if ($report->isSubscriptionExpired() && $subscription !== null) {
                $subscription->delete();
                $allTransient = false;
                continue;
            }

            // Ver DEC-11: fallo determinístico por par de llaves VAPID, no de infraestructura.
            if ($status === 403 && str_contains((string) $report->getResponse()?->getBody(), 'VapidPkHashMismatch')) {
                Log::critical('WebPushGateway: VAPID key mismatch (VapidPkHashMismatch) — las llaves configuradas en APP_VAPID_* no coinciden con las usadas por el navegador al crear esta suscripción. No es recuperable reintentando; si ocurre en múltiples suscripciones a la vez, revisar si se rotaron las llaves VAPID sin coordinar con mobile.', [
                    'subscription_guid' => $subscription?->guid,
                ]);
                $allTransient = false;
                continue;
            }

            if (! in_array($status, [null, 429], true) && $status < 500) {
                $allTransient = false; // 4xx definitivo (endpoint inválido, payload rechazado, etc.)
            }
        }

        if ($sentAny) {
            return DeliveryResult::sent('push-' . $message->idempotencyKey);
        }

        // Transitorio real (timeout/5xx/429 en TODAS las suscripciones): dejar que el job
        // reintente con backoff, igual que Kapso/Twilio ante errores de infraestructura.
        if ($allTransient) {
            throw new RuntimeException("Fallo transitorio de push para todas las suscripciones: {$lastReason}");
        }

        return DeliveryResult::failed($lastReason);
    }
}
```
**Nota para el dev que implemente esto**: los nombres exactos de métodos de `Minishlink\WebPush\MessageSentReport` (`isSuccess()`, `isSubscriptionExpired()`, `getReason()`, `getRequest()`, `getResponse()`) están tomados de memoria de la API pública de `minishlink/web-push` — verificar contra la versión real instalada (`composer show minishlink/web-push`) antes de dar esto por definitivo, la librería no está instalada en el proyecto todavía y no pude leer su código fuente. Mobile marcó específicamente este punto (D1 del review): confirmar si `MessageSentReport` expone el `endpoint` de la suscripción de forma más directa que reconstruirlo desde `getRequest()->getUri()` — si existe un accessor más directo, usarlo en vez del `keyBy('endpoint')` de arriba.

### Archivos a modificar

#### `back/composer.json`
Agregar a `require`: `"minishlink/web-push": "^9.0"` (verificar compatibilidad exacta con `php: ^8.2` al correr `composer require`).

#### `back/.env.example`
```
# Web Push (VAPID). El par lo GENERA este backend (ver DEC-12) — nunca se recibe de mobile,
# la privada no debe salir nunca del proceso que firma los envíos. A mobile solo se le pasa
# la pública, una vez, fuera de banda, por ambiente.
# Rotar este par exige purgar TODAS las filas de push_subscriptions y que cada dispositivo
# vuelva a suscribirse: firmar con un par distinto al que usó el navegador para crear la
# suscripción falla con 403 VapidPkHashMismatch de forma permanente, sin arreglo posible del
# lado servidor (ver DEC-11). Avisar a mobile ANTES de rotar.
APP_VAPID_PUBLIC_KEY=
APP_VAPID_PRIVATE_KEY=
APP_VAPID_SUBJECT=mailto:soporte@sav.app
```

#### `back/config/notifications.php`
Agregar dentro de `'channels'`:
```php
'push' => [
    'gateway' => \App\Notifications\Gateways\WebPush\WebPushGateway::class,
],
```
Y al nivel raíz del array:
```php
// Canales en los que se registra un AlertRecipient por defecto para cada destinatario
// resuelto por roles (ver AlertRecipientFactory). Push es adicional/paralelo a Whatsapp,
// no un fallback (ver DEC-08) — no participa de 'fallback' más abajo.
'default_channels' => [\App\Notifications\Enums\Channel::Whatsapp, \App\Notifications\Enums\Channel::Push],
```
`'fallback'` queda sin cambios (push no entra ahí — DEC-08).

#### `back/app/Notifications/NotificationServiceProvider.php`
Agregar el binding singleton, mismo patrón que Twilio/Kapso (falla rápido si falta config):
```php
$this->app->singleton(\App\Notifications\Gateways\WebPush\WebPushGateway::class, function ($app) {
    $publicKey = trim((string) env('APP_VAPID_PUBLIC_KEY', ''));
    $privateKey = trim((string) env('APP_VAPID_PRIVATE_KEY', ''));

    if ($publicKey === '' || $privateKey === '') {
        throw new NotificationConfigurationException('Faltan APP_VAPID_PUBLIC_KEY y/o APP_VAPID_PRIVATE_KEY.');
    }

    return new \App\Notifications\Gateways\WebPush\WebPushGateway(
        new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => env('APP_VAPID_SUBJECT', 'mailto:soporte@sav.app'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]),
    );
});
```
Nota: usar `env()` directo (no `config('notifications.*')`) es intencional aquí para replicar el patrón exacto ya usado con Twilio/Kapso en este mismo archivo — revisar si el resto del proyecto prefiere pasar por `config()` primero por compatibilidad con `config:cache` antes de fijar esto como definitivo (el `config/notifications.php` actual sí resuelve sus valores en tiempo de carga vía `env()`, así que es consistente).

#### `back/app/Notifications/Models/AlertRecipient.php` — modificar `toDto()`
Agregar rama explícita para `Channel::Push` ANTES del `match` existente (que solo cubre Whatsapp/Sms/Email vía `Contact`):
```php
public function toDto(): Recipient
{
    if ($this->channel === Channel::Push) {
        return $this->toPushDto();
    }

    // ... match existente sin cambios ...
}

private function toPushDto(): Recipient
{
    $hasActiveSubscription = PushSubscription::where('user_id', $this->userProfile->user_id)->exists();

    if (! $hasActiveSubscription) {
        throw new RecipientContactNotFoundException(
            "El perfil {$this->userProfile->guid} no tiene ninguna suscripción push activa",
        );
    }

    return new Recipient(
        userId: $this->userProfile->user_id,
        phone: null,
        name: $this->userProfile->user->name,
        channel: Channel::Push,
    );
}
```
Reutiliza el mismo camino de fallo que WhatsApp/Email sin contacto (`RecipientContactNotFoundException` → `DeliverAlertJob` lo captura, marca `Failed`, loggea `warning` y llama `ChannelFallbackService::attempt()` — que no hará nada porque `push` no tiene fallback configurado, ver DEC-08). Cero cambios en `DeliverAlertJob`. Tras la corrección de DEC-08, este chequeo ya **no es el filtro principal** (ese rol lo cumple `AlertRecipientFactory::hasActiveSubscription()` antes de crear la fila) — queda como red de seguridad para el caso borde de que el usuario revoque el permiso push entre el momento en que se creó el `AlertRecipient` y el momento real del despacho.

#### Los 4 builders — agregar rama `Channel::Push` explícita
`ProgramCreatedMessageBuilder`, `ProgramCancelledMessageBuilder`, `ProgramTaskDueMessageBuilder`, `ProgramPdfShareMessageBuilder`: agregar un `if ($recipient->channel === Channel::Push) return new PushContent(...)` **antes** del fallback a `TemplateContent` (que es específico de WhatsApp — un `AlertRecipient` de canal Push que cae al `TemplateContent` por descuido rompería `WebPushGateway`, que solo entiende `PushContent`).

**Actualizado tras review de mobile (sección A):** los 3 campos que antes faltaban (`tag`, `url`, `data.requires_confirmation`) ahora son obligatorios en todos los builders — sin ellos el SW ya en producción trata la notificación como rota (duplicada por reintento, sin persistir en pantalla, apuntando siempre al home). Ejemplo para `ProgramTaskDueMessageBuilder`:
```php
if ($recipient->channel === Channel::Push) {
    return new PushContent(
        title: 'Recordatorio de tarea',
        body: "{$program->protocol->name}: {$payload->message}",
        tag: "alert-{$alert->guid}",
        url: "programas/{$program->guid}",
        data: [
            'requires_confirmation' => (bool) $alert->require_confirmation,
            'alert_guid' => $alert->guid,
            'program_guid' => $program->guid,
        ],
    );
}
```
Mismo patrón en los otros 3 (título/body ajustados al contenido de cada uno — `ProgramCreatedMessageBuilder` y `ProgramCancelledMessageBuilder` ya tienen el texto en español armado para la rama `Email`, reusar esa redacción; `tag`/`url`/`data` se arman igual en los 4, siempre con `guid`, nunca con un id numérico).

#### Los 4 call sites — reemplazar el `foreach` inline por `AlertRecipientFactory`
`GenerateProgramTaskDueAlertsListener`, `ScheduleProgramCreatedAlertListener`, `HandleProgramCancelledListener`: inyectar `AlertRecipientFactory` por constructor, reemplazar:
```php
foreach ($recipients as $manager) {
    AlertRecipient::create([... 'channel' => Channel::Whatsapp, ...]);
}
```
por:
```php
$this->recipientFactory->createForManagers($alert, $recipients);
```

`ProgramShareService::sendPdfToRecipients`: mismo reemplazo, pero como además dispara `DeliverAlertJob::dispatch()` inmediatamente (no espera al `scheduled_at`, ver código actual línea ~109-119), hay que iterar la `Collection` que devuelve `createForManagers()`:
```php
$createdRecipients = $this->recipientFactory->createForManagers($alert, $recipients);
$createdRecipients->each(fn (AlertRecipient $r) => DeliverAlertJob::dispatch($r->id));
```

### Tests a generar (dispatch pipeline)
- `WebPushGatewayTest` (unit, mockeando `Minishlink\WebPush\WebPush` o su cliente HTTP interno — seguir el patrón de mocking HTTP usado en `KapsoWhatsappGatewayTest`/`TwilioWhatsappGatewayTest` si existen, si no, mock directo de la clase `WebPush`):
  - Una suscripción, envío exitoso → `DeliveryResult::sent(...)`.
  - Sin suscripciones activas para el usuario → `DeliveryResult::failed('sin_suscripciones_activas')`, sin llamar al cliente.
  - Dos suscripciones, una exitosa y otra falla 4xx → `DeliveryResult::sent(...)` (basta con que una llegue), y la fallida NO se borra si no es 404/410.
  - Suscripción responde 404/410 → se borra la fila de `push_subscriptions` y no cuenta como éxito si era la única.
  - Todas las suscripciones fallan con 5xx/timeout → lanza excepción (transitorio, deja reintentar).
  - 403 con `VapidPkHashMismatch` en el body → `DeliveryResult::failed(...)`, `Log::critical` invocado (assertable con `Log::spy()`), la suscripción NO se borra (a diferencia de 404/410 — sigue siendo válida, el problema es de configuración del servidor).
  - `body` más largo que `BODY_MAX_LENGTH` → el JSON encolado hacia `queueNotification` lo lleva truncado, `title`/`tag`/`url`/`data` intactos (D2).
  - Dos suscripciones con el mismo `endpoint` reconstruido por `getRequest()->getUri()` en dos reports distintos → cada report resuelve la suscripción correcta vía `keyBy('endpoint')`, ninguna queda huérfana (D1).
- `AlertRecipientFactoryTest` (unit): con `default_channels = [Whatsapp, Push]`, 2 managers y AMBOS con suscripción push activa → crea 4 `AlertRecipient` (2 managers × 2 canales); con `default_channels = [Whatsapp]` crea 2 sin importar suscripciones; con 2 managers y SOLO uno con suscripción activa → crea 3 (2 Whatsapp + 1 Push, el manager sin suscripción se salta el canal Push en silencio, sin fila `Failed`); con 2 managers y NINGUNO con suscripción → crea 2 (solo Whatsapp).
- `AlertRecipientToDtoTest` o extender el test existente de `AlertRecipient` (si existe): canal Push con suscripción activa → `Recipient` con `phone=null`, `channel=Push`; canal Push sin suscripción → `RecipientContactNotFoundException`.
- Extender `DeliverAlertJobTest` con al menos un caso end-to-end canal Push usando `FakeGateway` (`new FakeGateway(Channel::Push)`, ya soportado por el constructor existente de `FakeGateway`) para confirmar que el flujo completo (builder → pipeline → gateway → update de estado) funciona igual que WhatsApp sin tocar `DeliverAlertJob`.
- Feature test de humo: crear un `Program` completo y verificar que se generan `AlertRecipient` tanto `Whatsapp` como `Push` para cada manager (cubre `AlertRecipientFactory` integrado en `GenerateProgramTaskDueAlertsListener`).

## Cambios en FRONTEND
Sin cambios en `front/`. **Confirmado con mobile (`PUSH_PLAN_REVIEW.md`, "Fuera de alcance, confirmado"):** la PWA es un codebase separado de este monorepo, gestionado por el equipo mobile — no el SPA Vue de `front/src/modules/notifications` (que es solo la campanita/inbox in-app, un concepto distinto). Cero trabajo de frontend en este plan.

## Orden de implementación
1. `composer require minishlink/web-push`; agregar `APP_VAPID_*` a `.env.example` y al `.env` local (con un par de prueba generado localmente para desarrollo — nunca el real de mobile en local).
2. Migración `create_push_subscriptions_table` + `php artisan migrate`.
3. Modelo `PushSubscription`.
4. `PushSubscriptionRepositoryInterface` + `PushSubscriptionRepositoryEloquent` + bind en `AppServiceProvider`.
5. `StorePushSubscriptionRequest` + `DeletePushSubscriptionRequest`.
6. `PushSubscriptionResource`.
7. `PushSubscriptionService`.
8. `PushSubscriptionController`.
9. `routes/api/push-subscriptions.php`.
10. Feature tests del módulo self-service (correr y validar antes de seguir — esta mitad ya es entregable de forma independiente).
11. `PushContent` en `App\Notifications\Data`.
12. `config/notifications.php`: entrada `push` + `default_channels`.
13. `AlertRecipient::toDto()` — rama Push.
14. `WebPushGateway` + binding en `NotificationServiceProvider`.
15. Rama `Channel::Push` en los 4 builders (`ProgramCreated`, `ProgramCancelled`, `ProgramTaskDue`, `ProgramPdfShare`).
16. `AlertRecipientFactory`.
17. Rewire de los 4 call sites (3 listeners + `ProgramShareService`) para usar `AlertRecipientFactory`.
18. Tests unitarios de `WebPushGateway`, `AlertRecipientFactory`, rama Push de `toDto()`.
19. Extender `DeliverAlertJobTest` con caso Push vía `FakeGateway`.
20. Feature test de humo end-to-end (crear `Program` → verificar `AlertRecipient` Whatsapp+Push generados).
21. Correr `composer test` completo.
22. ~~Confirmar con mobile DEC-01 y DEC-08~~ — ya confirmados, ver `PUSH_PLAN_REVIEW.md` (B1 y C2).
23. Coordinación operativa (DEC-12): **generar** el par de llaves VAPID reales (no se reciben de mobile), cargarlas en `.env` de cada ambiente (staging/producción), pasarle la pública a mobile una vez fuera de banda, y **documentar el runbook de "si hay que rotarlas, hay que purgar `push_subscriptions` y avisar a mobile ANTES de rotar"** en el lugar que el equipo use para runbooks operativos (no cubierto por este plan de código).

## Riesgos y consideraciones

- **DEC-01 y DEC-08 quedaron confirmados/resueltos con el equipo mobile** — ver `PUSH_PLAN_REVIEW.md`. Ya no son puntos abiertos de este plan.
- **Multi-tenant (regla dura #4) — verificado, no violado**: `WebPushGateway` consulta `PushSubscription::where('user_id', ...)` sin filtrar por vet, lo cual es correcto y no una violación: `PushSubscription` es una entidad a nivel `User` (dispositivo físico), no scopeada a tenant por diseño (ver DEC-05). El scoping por tenant ya ocurrió río arriba, cuando se generó el `AlertRecipient` a partir de `program->managers` (que sí están scopeados al `vet_id` del programa). `DispatchDueAlerts` tampoco filtra por vet explícitamente, pero eso es el comportamiento preexistente del sistema (un comando de sistema que procesa alertas de todos los tenants, cada una ya llevando su propio `vet_id`), no algo que este plan introduzca.
- **`Channel::Push` ya existía en el enum sin ningún gateway ni AlertRecipient que lo usara** — es decir, alguien ya anticipó este trabajo pero nunca lo completó. Vale la pena confirmar con el equipo si hay contexto/decisiones previas no documentadas sobre cómo se pensaba cablear (ver también `.claude/docs/plans/arquitectura-notificaciones.md` y `kapso-whatsapp-provider-plan.md`, que no llegué a leer en detalle — revisarlos antes de implementar por si hay una decisión explícita sobre Push que este plan está pisando).
- **DEC-08 (revisada) reduce, no elimina, el volumen extra**: con el gating por suscripción activa, el volumen de `AlertRecipient`/`DeliverAlertJob` solo crece para los destinatarios que efectivamente instalaron la PWA — no 2N fijo como en la versión original. Igual vale monitorear el volumen real una vez que haya adopción de la PWA.
- **Riesgo operativo documentado explícitamente por mobile**: VAPID mismatch (DEC-11) es irrecuperable del lado servidor. Este plan solo puede loggear `critical` y fallar limpiamente por suscripción — no hay lógica de código que lo prevenga. La única mitigación real es de proceso: nunca generar un par VAPID nuevo sin coordinarlo con mobile y correr una purga+re-suscripción. Con DEC-12 (la nube genera las llaves) el riesgo de que dos equipos usen pares distintos por descoordinación baja, pero no desaparece — sigue siendo puramente de proceso.
- **`minishlink/web-push` no está instalado** — no pude verificar su API real contra código fuente. Los nombres de métodos en `WebPushGateway` (`isSuccess()`, `isSubscriptionExpired()`, `getReason()`, etc.) están tomados de memoria de la librería y deben verificarse contra la versión que realmente se instale. Mobile marcó puntualmente el método para resolver el endpoint del `MessageSentReport` (D1) como el más urgente de confirmar.
- **`content_encoding`**: se acepta `aes128gcm` (default, moderno) y `aesgcm` (legacy, algunos Safari viejos). Mobile confirmó (D4) que dejarlo así está bien — no restringir a `aes128gcm` únicamente.
- **`front/` fuera de alcance** — confirmado por mobile, ver "Cambios en FRONTEND".
- **`BODY_MAX_LENGTH = 500` (D2) es un valor de arranque, no medido**: el límite real de un push es ~4KB de payload *total* (title + body + tag + url + data + overhead JSON), no solo de `body`. 500 caracteres de `body` deja margen holgado, pero conviene medir el payload real serializado antes de dar el número por definitivo, sobre todo si `data` crece a futuro.
- **Deuda de confirmación de alertas, documentada pero fuera de alcance de este plan** (sección E del review, decisión de negocio ya tomada: por ahora alcanza con que la notificación quede fija en pantalla): `alerts.confirmed_at`/`confirmed_by` existen en el modelo pero **nada los escribe hoy**, ni de este lado ni del lado mobile. `requires_confirmation` en el payload de push (A2) solo controla que la notificación quede fija hasta que la toquen — no registra que alguien la haya visto o confirmado. Si el negocio pide más adelante saber si el encargado efectivamente confirmó, hace falta contrato nuevo: un endpoint de confirmación del lado backend, y devolver `confirmed_at`/`delivered_at` hacia el dispositivo (encajaría en el bloque `pull.alerts` del sync). No diseñado acá — solo dejado anotado para no perderlo.

## Pendientes / fuera de alcance
- Endpoint de exposición de la VAPID public key — explícitamente fuera de alcance según el brief (se entrega una sola vez fuera de banda, va en `.env`; ver DEC-12 sobre quién la genera).
- UI/composable de gestión de dispositivos push desde algún panel de administración — no aplica, la PWA es un codebase externo (confirmado).
- Métricas/observabilidad de tasa de entrega push (ej. dashboard de suscripciones activas vs expiradas) — no pedido, se podría construir sobre `push_subscriptions` + `alert_recipients.status='push'` después.
- Purga automática/scheduled de suscripciones nunca usadas o con `device_updated_at` muy viejo — no pedido, columna queda disponible para eso a futuro.
- `HealthPlanMonth` y `EventReminder` (`AlertType` existentes sin builder implementado) — gap preexistente no relacionado con este plan, no se toca.
- **Confirmación de alertas (`confirmed_at`/`confirmed_by`)** — decisión de negocio tomada: no entra en este plan. Ver nota completa en "Riesgos y consideraciones" arriba. Si se retoma, requiere: endpoint de confirmación + extender el sync para devolver `confirmed_at`/`delivered_at`.
- `POST /vets/{vet}/sync` (red de contención para suscripciones que quedaron con `cloud_id null`) — confirmado por mobile como pendiente y sin prioridad; mientras no exista, una suscripción que falla al registrarse no tiene reintento automático hasta que el usuario vuelva a activar notificaciones manualmente. Aceptado como limitación conocida, no se resuelve en este plan.
