# Plan técnico: Kapso como proveedor alternativo de WhatsApp (con webhooks)

## Input procesado

Pedido directo del usuario: implementar Kapso (kapso.com / api.kapso.ai) como alternativa a Twilio para el envío de WhatsApp, contemplando webhooks.

Documentación de referencia consultada:
- Envío: `https://docs.kapso.ai/api/meta/whatsapp/messages/send-a-message`
- Seguridad de webhooks: `https://docs.kapso.ai/docs/platform/webhooks/security`
- Tipos de evento: `https://docs.kapso.ai/docs/platform/webhooks/event-types`
- Recepción: `https://docs.kapso.ai/docs/whatsapp/receive-messages`

## Resumen ejecutivo

La arquitectura de notificaciones **ya está preparada** para un segundo proveedor de WhatsApp: `NotificationChannelGateway` es una interfaz con contrato explícito de errores (definitivos en `DeliveryResult`, transitorios como excepción para que la cola aplique backoff), y `GatewayRegistry` resuelve el gateway por canal leyendo `config('notifications.channels')`. Agregar Kapso no requiere tocar `DeliverAlertJob`, `DeliveryPipeline`, `OptOutPolicy` ni los modelos.

Sin embargo hay **cuatro obstáculos reales** que no se resuelven simplemente escribiendo una clase nueva:

**1. El concepto de "template" de Twilio está filtrado dentro de los builders.** `TemplateContent::$templateId` guarda un `contentSid` de Twilio (`HX...`), y los tres builders lo resuelven ellos mismos con `config('notifications.templates')[$type->value]` (`ProgramCreatedMessageBuilder.php:36`, `ProgramCancelledMessageBuilder.php:36`, `ProgramTaskDueMessageBuilder.php:40`). Kapso es un passthrough de la Cloud API de Meta: identifica templates por **nombre + código de idioma + parámetros ordenados**, no por un SID opaco. Es decir, el builder —que debería ser agnóstico del transporte— hoy conoce un identificador propietario de Twilio. Sin refactor, el builder tendría que preguntar "¿qué proveedor está activo?", que es exactamente la responsabilidad que el gateway existe para encapsular.

**2. Los webhooks no existen en ninguna parte del proyecto.** No hay una sola ruta de webhook (`rg -i "webhook|callback" back/routes/` no devuelve nada funcional). Pero —y esto es lo relevante— el esquema **ya fue diseñado para recibirlos**: `DeliveryStatus` declara `Delivered` y `Read` (`Enums/DeliveryStatus.php:9-10`), `alert_recipients` tiene columnas `delivered_at` y `provider_message_id`, y `AlertRecipient` las declara en `$fillable`. Nada en el código actual escribe esos estados: `DeliverAlertJob` solo puede dejar un recipient en `Sent`, `Failed` o `Suppressed`. O sea, es un hueco previsto, no un rediseño. Kapso lo llena de forma natural con `whatsapp.message.{sent,delivered,read,failed}`.

**3. `provider_message_id` no tiene índice.** El webhook correlaciona por el `wamid` que devuelve el envío, y ese es el único camino de vuelta al recipient. Sin índice, cada evento entrante (y hay hasta 3 por mensaje: sent, delivered, read) hace un full scan de `alert_recipients`. La migración original solo indexa `guid`, `idempotency_key` y la tupla `(alert_id, user_profile_id, channel)`.

**4. El fallback hoy solo puede disparar de forma sincrónica.** `DeliverAlertJob::tryFallback()` es un método `private` que se invoca cuando el envío devuelve `Failed` en el momento, o cuando la cola agota los reintentos (`failed()`). Con webhooks aparece un camino nuevo que hoy no existe: el mensaje se acepta con `200` (recipient queda `Sent`), y **minutos después** Meta lo rechaza y llega `whatsapp.message.failed`. Ese fallo asincrónico también debe caer a email, y el handler del webhook no puede llamar a un método privado de un job. La lógica de fallback necesita extraerse a un servicio compartido.

El plan resuelve los cuatro, deja Twilio funcionando sin cambios de comportamiento y hace la selección de proveedor un flip de env var.

## Decisiones tomadas

**DEC-01 — Kapso es un gateway nuevo del canal `whatsapp`, NO un canal nuevo**

Decisión: no se agrega `Channel::Kapso`. `Channel` sigue siendo `whatsapp|sms|email|push`. Kapso y Twilio son dos implementaciones intercambiables de `NotificationChannelGateway` para `Channel::Whatsapp`.

Justificación: `Channel` es una dimensión de negocio — determina el tipo de contacto que se busca en `AlertRecipient::toDto()` (`ContactType::Whatsapp`), la clave del fallback en `config('notifications.fallback')`, y la tupla única `(alert_id, user_profile_id, channel)` de la migración. Un proveedor es una dimensión de infraestructura. Mezclarlas rompería tres cosas a la vez: la restricción única permitiría duplicar el mismo mensaje al mismo perfil por dos proveedores, el opt-out de un usuario a "whatsapp" no aplicaría a "kapso", y el usuario final vería un canal "Kapso" en la UI de preferencias, que no significa nada para él.

Alternativa descartada: `Channel::KapsoWhatsapp`. Descartada por lo anterior: contaminaría el dominio con un nombre de proveedor y obligaría a duplicar entradas en `fallback`, opt-outs y UI.

**DEC-02 — El proveedor de WhatsApp se elige por config, resuelto en el `GatewayRegistry` existente**

Decisión: `config/notifications.php` pasa a resolver el gateway de `whatsapp` de forma indirecta, vía `WHATSAPP_PROVIDER` (`twilio` por defecto). El `GatewayRegistry` no cambia de código: sigue recibiendo un mapa `canal => ['gateway' => FQCN]`; lo que cambia es cómo se arma ese FQCN en el archivo de config.

Justificación: `GatewayRegistry::for()` (`Registries/GatewayRegistry.php:25-31`) ya hace `$this->container->make($class)`. Si el config entrega la clase correcta, el registry es indiferente al proveedor. Cero cambios en el runtime de resolución, y el default `twilio` garantiza que nada se rompe para quien no defina la variable.

Alternativa descartada: inyectar un `WhatsappProviderResolver` que elija en tiempo de ejecución por destinatario/país. Descartada por ahora: no hay requerimiento de ruteo por país y agregaría una capa sin caso de uso. Se deja anotado en "Extensiones futuras" porque el diseño elegido no la bloquea.

**DEC-03 — `TemplateContent` deja de transportar el ID propietario y pasa a transportar el `AlertType`**

Decisión: `TemplateContent` cambia de `(string $templateId, array $variables, string $locale)` a `(AlertType $type, array $variables)`. Cada gateway resuelve `$type` a su identificador propio: Twilio al `contentSid` (`HX...`), Kapso a `['name' => ..., 'language' => ...]`. Los builders dejan de leer `config('notifications.templates')`.

Justificación: es el núcleo del problema. Un builder responde "¿qué dice el mensaje?"; un gateway responde "¿cómo lo pide este proveedor?". Hoy el builder responde parte de la segunda pregunta, y por eso agregar Kapso sin este refactor obligaría a un `if ($provider === 'kapso')` dentro de los tres builders — el acoplamiento se multiplicaría por cada builder futuro (`HealthPlanMonth` y `EventReminder` ya están declarados en `AlertType` sin builder). Mover la resolución al gateway hace que agregar un tercer proveedor sea una clase nueva y una entrada de config, sin tocar builders.

Nota sobre `$locale`: hoy `TemplateContent::$locale = 'es_AR'` existe pero **nadie lo usa** — `TwilioWhatsappGateway` no lo pasa en el payload porque el `contentSid` de Twilio ya lleva el idioma embebido. En Kapso el idioma es obligatorio y va en `template.language.code`. Por eso el idioma se mueve a la config del template por proveedor, donde sí tiene sentido, y se elimina del DTO.

Alternativa descartada: mantener `templateId` y meter en él un string compuesto tipo `"kapso:sav_program_created:es"`. Descartada: es un tipo `string` haciendo de tipo compuesto, ilegible y sin validación.

**DEC-04 — Las variables se mantienen como mapa ordinal `["1" => ..., "2" => ...]`**

Decisión: `TemplateContent::$variables` conserva el formato actual (claves `"1"`, `"2"`, `"3"`). El gateway de Kapso hace `ksort()` y las mapea a `template.components[0].parameters[]` posicionales.

Justificación: es el formato que ya usan los tres builders y sus tests, y es el que Meta acepta en su forma posicional (documentada). Cambiarlo a parámetros nombrados (`parameter_name`) obligaría a que el nombre del parámetro en Meta coincida exactamente con la clave del builder, sumando un contrato frágil entre código y dashboard, sin ningún beneficio: el orden es determinístico y ya está fijado por el template.

Riesgo asumido y controlado: `ksort()` sobre claves string ordena `"10"` antes que `"2"`. Ningún template actual pasa de 3 variables, pero el gateway usará ordenamiento numérico explícito (`ksort($vars, SORT_NUMERIC)`) para que el bug no aparezca nunca.

**DEC-05 — La normalización del teléfono se centraliza en `AlertRecipient::toDto()`, no en cada gateway**

Decisión: `toDto()` normaliza el valor del contacto a E.164 sin `+` (solo dígitos) antes de construir el `Recipient`. Los gateways asumen ese contrato.

Justificación: `Recipient::$phone` **ya documenta** "E.164 without leading `+`, normalized" (`Data/Recipient.php:9`), pero `toDto()` pasa `$contact->value` crudo desde la tabla `contacts` (`Models/AlertRecipient.php:73`). O sea, el contrato está escrito y no se cumple. Hoy eso produce un bug silencioso en Twilio: `TwilioWhatsappGateway.php:28` arma `'whatsapp:+' . $phone`, así que un contacto guardado como `+54 11 3429 0838` genera `whatsapp:++54 11 3429 0838`. Kapso hereda el mismo problema (`to` espera solo dígitos). Normalizar en dos gateways es duplicar; normalizar en el DTO cumple el contrato que ya está declarado y arregla Twilio de paso.

Alternativa descartada: normalizar al guardar el contacto (mutator en `Contact`). Es lo correcto a largo plazo, pero no cubre los registros ya existentes en la base y requiere una migración de datos. Se deja como tarea aparte en "Extensiones futuras"; la normalización en lectura es idempotente y convive sin problema con una futura normalización en escritura.

**DEC-06 — Un solo endpoint de webhook, con verificación de firma en un middleware dedicado**

Decisión: `POST /api/v1/webhooks/kapso`, sin `auth:sanctum`, protegido por un middleware `VerifyKapsoWebhookSignature` que valida el header `X-Webhook-Signature` (HMAC-SHA256 hex del **cuerpo crudo**) contra `KAPSO_WEBHOOK_SECRET` usando `hash_equals()`.

Justificación: la firma es la autenticación — por eso no lleva `auth:sanctum` (Kapso no tiene un token de usuario de la plataforma). Va en middleware y no en el controlador por dos razones: se ejecuta antes de que Laravel toque el body, y deja el controlador testeable sin construir firmas. El punto crítico de implementación: la doc de Kapso advierte explícitamente "Always verify against the raw JSON payload, not a parsed object", así que hay que usar `$request->getContent()` y **nunca** `json_encode($request->all())` — re-serializar cambia el orden de claves, el escapado de unicode y las barras, y la firma no coincide nunca.

Como está bajo `routes/api/`, no hay sesión ni CSRF que excluir: `bootstrap/app.php` registra `api: routes/api.php`, y ese archivo hace glob de `routes/api/*.php`, así que un archivo nuevo se carga solo.

Alternativa descartada: verificar la firma dentro del controlador. Descartada porque el `FormRequest`/controller ya recibe el body parseado y es fácil caer en el error de re-serializar.

**DEC-07 — Las transiciones de estado por webhook son monótonas y nunca retroceden**

Decisión: un servicio `RecordDeliveryStatus` aplica los eventos con un orden de precedencia (`pending < sent < delivered < read`), y descarta cualquier evento que intente bajar de nivel. `failed` es terminal y solo se acepta si el estado actual no es ya `delivered` o `read`.

Justificación: los webhooks **no llegan ordenados** — es un sistema distribuido con reintentos (10s, 40s, 90s según la doc). Un `sent` reintentado puede llegar después del `delivered`. Sin guarda de monotonía, un recipient que ya fue leído volvería a `sent` y el `delivered_at` se perdería. Y hay un caso peor: `Suppressed` (opt-out) nunca debe ser sobreescrito por un webhook, porque significa una decisión explícita del destinatario, no un estado de transporte — el comentario en `DeliverAlertJob.php:62-63` es explícito sobre esa semántica y hay que respetarla acá también.

**DEC-08 — El fallback se extrae de `DeliverAlertJob` a un servicio, para que el webhook también pueda dispararlo**

Decisión: `DeliverAlertJob::tryFallback()` (privado) se extrae a `App\Notifications\Services\ChannelFallbackService`, inyectado en el job y usado también por el handler del webhook cuando llega `whatsapp.message.failed`.

Justificación: es el punto que más fácil se pasa por alto de todo el plan. Hoy `Failed` solo ocurre de forma sincrónica, así que el fallback vive donde ocurre el fallo. Con Kapso aparece el fallo **asincrónico**: la API responde `200` con un `wamid` (recipient → `Sent`), y después Meta rechaza el mensaje (número inexistente, usuario bloqueó a la empresa, ventana de 24h cerrada) y eso llega como webhook. Si el fallback no se dispara ahí, el alert queda en `Sent` para siempre y el destinatario nunca recibe nada — un fallo silencioso, la peor clase. La lógica ya es autocontenida (consulta si el canal alternativo se intentó, crea el recipient, despacha el job), así que extraerla es mecánico y no cambia comportamiento para Twilio.

**DEC-09 — El controlador del webhook persiste y responde rápido; el procesamiento pesado va a la cola**

Decisión: el controlador valida firma, deduplica por `X-Idempotency-Key`, guarda el evento crudo en `whatsapp_webhook_events` y despacha `ProcessKapsoWebhookEventJob`. Responde `200` inmediatamente.

Justificación: Kapso exige `200` en menos de 10 segundos y reintenta si no lo recibe. Si el handler resuelve el recipient, actualiza estado y eventualmente dispara un fallback (que crea otro recipient y despacha otro job) todo inline, se corre el riesgo de pasarse del budget bajo carga y recibir eventos duplicados. Guardar el crudo además da trazabilidad: cuando un estado no cuadre, el payload original está en la base.

Advertencia para local: con `QUEUE_CONNECTION=sync` (valor actual del `.env` local) el "despacho a la cola" corre inline igual. Es aceptable para probar, pero significa que el timing de 10s **no se está validando en local**. En staging/producción va con `database` o `redis`.

## Cambios en BACKEND

### Migraciones

#### `back/database/migrations/XXXX_XX_XX_add_provider_message_id_index_to_alert_recipients.php` (nueva)

```php
Schema::table('alert_recipients', function (Blueprint $table) {
    $table->index('provider_message_id');
});
```

Motivo: es la clave de correlación del webhook (`message.id` → `provider_message_id`) y hoy no está indexada.

#### `back/database/migrations/XXXX_XX_XX_create_whatsapp_webhook_events_table.php` (nueva)

```php
Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
    $table->id();
    $table->string('provider')->default('kapso');
    $table->string('idempotency_key')->unique();   // X-Idempotency-Key
    $table->string('event_type');                   // whatsapp.message.delivered
    $table->string('provider_message_id')->nullable()->index();
    $table->json('payload');
    $table->timestamp('processed_at')->nullable();
    $table->string('error')->nullable();
    $table->timestamps();
});
```

El `unique` en `idempotency_key` es el mecanismo de deduplicación: un insert duplicado falla y se responde `200` sin reprocesar.

### Config

#### `back/config/notifications.php` (modificar)

```php
'channels' => [
    'whatsapp' => [
        'gateway' => match (env('WHATSAPP_PROVIDER', 'twilio')) {
            'kapso'  => \App\Notifications\Gateways\Kapso\KapsoWhatsappGateway::class,
            'twilio' => \App\Notifications\Gateways\Twilio\TwilioWhatsappGateway::class,
            'fake'   => \App\Notifications\Gateways\Fake\FakeGateway::class,
        },
    ],
    'email' => [
        'gateway' => \App\Notifications\Gateways\Mail\MailGateway::class,
    ],
],

'fallback' => [
    'whatsapp' => ['email'],
],

'twilio' => [
    'sid'               => env('TWILIO_ACCOUNT_SID'),
    'token'             => env('TWILIO_AUTH_TOKEN'),
    'messaging_service' => env('TWILIO_TEMPLATE_MESSAGE_SERVICE'),
    // Movido desde el nivel raíz 'templates': el contentSid es propio de Twilio.
    'templates' => [
        'program.created'   => env('TWILIO_TEMPLATE_PROGRAM_CREATED'),
        'program.cancelled' => env('TWILIO_TEMPLATE_PROGRAM_CANCELLED'),
        'program.task_due'  => env('TWILIO_TEMPLATE_PROGRAM_TASK_DUE'),
    ],
],

'kapso' => [
    'api_key'         => env('KAPSO_API_KEY'),
    'phone_number_id' => env('KAPSO_PHONE_NUMBER_ID'),
    'base_url'        => env('KAPSO_BASE_URL', 'https://api.kapso.ai'),
    'api_version'     => env('KAPSO_API_VERSION', 'v24.0'),
    'webhook_secret'  => env('KAPSO_WEBHOOK_SECRET'),
    'timeout'         => env('KAPSO_TIMEOUT', 10),
    // Meta identifica templates por nombre + idioma, no por SID.
    'templates' => [
        'program.created'   => ['name' => env('KAPSO_TEMPLATE_PROGRAM_CREATED', 'sav_program_created'),   'language' => 'es'],
        'program.cancelled' => ['name' => env('KAPSO_TEMPLATE_PROGRAM_CANCELLED', 'sav_program_cancelled'), 'language' => 'es'],
        'program.task_due'  => ['name' => env('KAPSO_TEMPLATE_PROGRAM_TASK_DUE', 'sav_program_task_due'),  'language' => 'es'],
    ],
],
```

Nota: se eliminan los `contentSid` hardcodeados como default (`HXf21519...`, `HXbf82a4...`). Pertenecen a una cuenta Twilio que ya no es la que se usa, y un default inválido produce un fallo confuso (404 → `Failed` → fallback a email) en vez de un error claro de configuración.

#### `back/.env.example` (modificar)

```env
# WhatsApp provider: twilio | kapso | fake
WHATSAPP_PROVIDER=twilio

KAPSO_API_KEY=
KAPSO_PHONE_NUMBER_ID=
KAPSO_WEBHOOK_SECRET=
KAPSO_TEMPLATE_PROGRAM_CREATED=sav_program_created
KAPSO_TEMPLATE_PROGRAM_CANCELLED=sav_program_cancelled
KAPSO_TEMPLATE_PROGRAM_TASK_DUE=sav_program_task_due
```

### Archivos a modificar

#### `back/app/Notifications/Data/TemplateContent.php`

**Antes:**
```php
final readonly class TemplateContent implements MessageContent
{
    /** @param array<string,string> $variables Content API variables, e.g. ["1" => "...", "2" => "..."] */
    public function __construct(
        public string $templateId,
        public array $variables,
        public string $locale = 'es_AR',
    ) {}
}
```

**Después:**
```php
final readonly class TemplateContent implements MessageContent
{
    /**
     * Provider-agnostic template reference. Each gateway resolves $type to its own
     * identifier: a contentSid for Twilio, a name + language pair for Kapso/Meta.
     *
     * @param array<string,string> $variables Ordinal placeholders, e.g. ["1" => "...", "2" => "..."]
     */
    public function __construct(
        public AlertType $type,
        public array $variables,
    ) {}
}
```

#### `back/app/Notifications/Builders/ProgramCreatedMessageBuilder.php`

**Antes:**
```php
return new TemplateContent(
    templateId: config('notifications.templates')[AlertType::ProgramCreated->value],
    variables: [
        '1' => $recipient->name,
        '2' => $program->protocol->name,
    ],
);
```

**Después:**
```php
return new TemplateContent(
    type: AlertType::ProgramCreated,
    variables: [
        '1' => $recipient->name,
        '2' => $program->protocol->name,
    ],
);
```

Mismo cambio, mecánico, en `ProgramCancelledMessageBuilder.php` (`AlertType::ProgramCancelled`) y `ProgramTaskDueMessageBuilder.php` (`AlertType::ProgramTaskDue`, 3 variables).

#### `back/app/Notifications/Gateways/Twilio/TwilioWhatsappGateway.php`

Dos cambios: resolver el `contentSid` desde su propia config, y dejar de re-agregar el `+` (ahora que `Recipient::$phone` viene normalizado por DEC-05, el `+` sigue siendo necesario para el formato `whatsapp:+E164` de Twilio, así que **se mantiene** — pero el valor de entrada ya es confiable).

**Antes:**
```php
$to = 'whatsapp:+' . $message->recipient->phone;

$sent = match (true) {
    $message->content instanceof TemplateContent => $this->twilio->messages->create($to, [
        'from' => $this->from,
        'contentSid' => $message->content->templateId,
        'contentVariables' => json_encode($message->content->variables),
    ]),
```

**Después:**
```php
$to = 'whatsapp:+' . $message->recipient->phone;

$sent = match (true) {
    $message->content instanceof TemplateContent => $this->twilio->messages->create($to, [
        'from' => $this->from,
        'contentSid' => $this->resolveContentSid($message->content->type),
        'contentVariables' => json_encode($message->content->variables),
    ]),
```

Se agrega:
```php
/** @throws \RuntimeException si el template no está configurado para este proveedor */
private function resolveContentSid(AlertType $type): string
{
    return $this->templates[$type->value]
        ?? throw new \RuntimeException("Sin contentSid de Twilio para el template {$type->value}");
}
```

El constructor pasa a recibir `array $templates` además de `Client $twilio` y `string $from`.

Motivo del `throw`: hoy un template faltante produce `null` → Twilio responde 4xx → `Failed` → fallback silencioso a email. Un error de configuración debe verse como error de configuración, no disfrazarse de fallo de entrega.

#### `back/app/Notifications/Models/AlertRecipient.php`

**Cambio:** normalizar el teléfono en `toDto()` para cumplir el contrato ya documentado en `Recipient::$phone`.

**Antes:**
```php
return new Recipient(
    userId: $this->userProfile->user_id,
    phone: $this->channel === Channel::Email ? null : $contact->value,
    ...
);
```

**Después:**
```php
return new Recipient(
    userId: $this->userProfile->user_id,
    phone: $this->channel === Channel::Email ? null : self::normalizePhone($contact->value),
    ...
);
```

```php
/** E.164 sin '+': solo dígitos. Los contactos se cargan con formatos variados ('+54 9 11 ...'). */
private static function normalizePhone(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}
```

#### `back/app/Notifications/Jobs/DeliverAlertJob.php`

**Cambio:** `tryFallback()` privado se reemplaza por una llamada al servicio inyectado.

- `handle()` recibe además `ChannelFallbackService $fallback`.
- Las dos llamadas a `$this->tryFallback($recipient)` en `handle()` pasan a `$fallback->attempt($recipient)`.
- `failed(Throwable $e)` resuelve el servicio del contenedor (`app(ChannelFallbackService::class)`) porque los hooks de fallo del job no reciben inyección por firma.
- Se elimina el método `private function tryFallback()`.

#### `back/app/Notifications/NotificationServiceProvider.php`

- El binding de `TwilioWhatsappGateway` pasa a inyectar también `config('notifications.twilio.templates')`.
- Se agrega binding de `KapsoWhatsappGateway` con su `PendingRequest` de Http ya configurado (base URL, `X-API-Key`, timeout).
- Se registra `ChannelFallbackService` (singleton) y `RecordDeliveryStatus`.

#### `back/bootstrap/app.php`

Registrar el alias del middleware de firma:
```php
$middleware->alias([
    'vet.tenant'      => \App\Http\Middleware\EnsureUserBelongsToVet::class,
    'kapso.signature' => \App\Notifications\Http\Middleware\VerifyKapsoWebhookSignature::class,
]);
```

### Archivos a crear

#### `back/app/Notifications/Gateways/Kapso/KapsoWhatsappGateway.php`

Implementa `NotificationChannelGateway`. Responsabilidades:

- `channel()` → `Channel::Whatsapp`.
- `send(OutboundMessage)`:
  - `POST {base_url}/meta/whatsapp/{api_version}/{phone_number_id}/messages` con header `X-API-Key`.
  - `TemplateContent` → body Meta con `type: template`, `template.name`, `template.language.code`, y `components[0] = ['type' => 'body', 'parameters' => [...]]` con parámetros posicionales `['type' => 'text', 'text' => $v]` en orden numérico.
  - `TextContent` → `type: text`, `text.body`.
  - `to` → `$message->recipient->phone` (solo dígitos, ya normalizado por DEC-05).
  - Éxito → `DeliveryResult::sent($response->json('messages.0.id'))`.
- **Contrato de errores** (crítico, es lo que hace que el retry/backoff del job funcione):
  - `4xx` → `DeliveryResult::failed(...)` con el mensaje del error de Meta. Es definitivo, no se reintenta.
  - `5xx`, timeout, error de conexión → **lanza** la excepción, para que `DeliverAlertJob` aplique su `$backoff` de `[60, 300, 900, 1800]`.
  - `429` (rate limit) → **lanza**, es transitorio.
  - Template no configurado → `RuntimeException` (error de config, no de entrega).

Este es el mismo contrato que documenta `NotificationChannelGateway.php:13-18` y que respeta `TwilioWhatsappGateway.php:45-51`. Invertirlo (devolver `failed` en un 5xx) desactivaría los reintentos en silencio.

#### `back/app/Notifications/Http/Middleware/VerifyKapsoWebhookSignature.php`

```php
public function handle(Request $request, Closure $next): Response
{
    $secret = config('notifications.kapso.webhook_secret');

    if (blank($secret)) {
        abort(500, 'KAPSO_WEBHOOK_SECRET no configurado');
    }

    $provided = (string) $request->header('X-Webhook-Signature');
    // Firma sobre el cuerpo CRUDO. Re-serializar con json_encode($request->all())
    // altera orden de claves y escapado, y la firma nunca coincide.
    $expected = hash_hmac('sha256', $request->getContent(), $secret);

    if (! hash_equals($expected, $provided)) {
        abort(401, 'Firma de webhook inválida');
    }

    return $next($request);
}
```

`hash_equals` es la comparación timing-safe que pide la doc de Kapso. El `abort(500)` cuando falta el secreto es deliberado: fallar abierto sería aceptar webhooks sin verificar.

#### `back/app/Notifications/Http/Controllers/KapsoWebhookController.php`

```php
public function __invoke(Request $request): JsonResponse
{
    $idempotencyKey = (string) $request->header('X-Idempotency-Key', '');
    $eventType      = (string) $request->header('X-Webhook-Event', $request->input('type', ''));

    if (blank($idempotencyKey)) {
        // Sin clave no se puede deduplicar; se sintetiza del cuerpo para no perder el evento.
        $idempotencyKey = 'sha256:' . hash('sha256', $request->getContent());
    }

    try {
        $event = WhatsappWebhookEvent::create([
            'provider'            => 'kapso',
            'idempotency_key'     => $idempotencyKey,
            'event_type'          => $eventType,
            'provider_message_id' => $request->input('message.id'),
            'payload'             => $request->all(),
        ]);
    } catch (UniqueConstraintViolationException) {
        return response()->json(['status' => 'duplicate']);  // 200: ya procesado
    }

    ProcessKapsoWebhookEventJob::dispatch($event->id);

    return response()->json(['status' => 'accepted']);
}
```

Siempre `200` salvo firma inválida. Un `4xx`/`5xx` acá dispara los reintentos de Kapso (10s/40s/90s) y no arregla nada si el problema es nuestro.

#### `back/app/Notifications/Models/WhatsappWebhookEvent.php`

Modelo simple, `payload` casteado a `array`, `processed_at` a `datetime`. Sin `HasGuid`: es una tabla interna de auditoría, no se expone por API.

#### `back/app/Notifications/Jobs/ProcessKapsoWebhookEventJob.php`

Carga el evento, delega en `RecordDeliveryStatus`, marca `processed_at` o guarda `error`.

#### `back/app/Notifications/Services/RecordDeliveryStatus.php`

El corazón de DEC-07. Mapea evento → estado y aplica la guarda de monotonía:

```php
private const PRECEDENCE = [
    'pending'   => 0,
    'sent'      => 1,
    'delivered' => 2,
    'read'      => 3,
];

private const EVENT_MAP = [
    'whatsapp.message.sent'      => DeliveryStatus::Sent,
    'whatsapp.message.delivered' => DeliveryStatus::Delivered,
    'whatsapp.message.read'      => DeliveryStatus::Read,
    'whatsapp.message.failed'    => DeliveryStatus::Failed,
];
```

Reglas:
1. Resolver el recipient por `provider_message_id` = `payload['message']['id']`. Si no existe → registrar y salir (puede ser un mensaje entrante o de otro sistema; no es un error).
2. Si el recipient está en `Suppressed` → **ignorar siempre**. Un opt-out no se sobreescribe con estado de transporte (misma semántica que `DeliverAlertJob.php:62-63`).
3. Si el recipient está en `Delivered` o `Read` y llega `failed` → ignorar. Un mensaje ya entregado no puede fallar retroactivamente.
4. `failed` en cualquier otro caso → `status = Failed`, guardar `failure_reason`, y llamar a `ChannelFallbackService::attempt()` (DEC-08).
5. Para el resto: aplicar solo si `PRECEDENCE[nuevo] > PRECEDENCE[actual]`. Setear `delivered_at` en `delivered`.
6. Todo dentro de una transacción con `lockForUpdate()` sobre el recipient: dos eventos concurrentes del mismo mensaje son un escenario normal.

#### `back/app/Notifications/Services/ChannelFallbackService.php`

Movimiento literal de `DeliverAlertJob::tryFallback()` a `public function attempt(AlertRecipient $recipient): void`. Sin cambios de lógica — la extracción es para habilitar el segundo llamador (el webhook), no para modificar comportamiento.

#### `back/routes/api/notifications-webhooks.php`

```php
Route::post('v1/webhooks/kapso', KapsoWebhookController::class)
    ->middleware('kapso.signature')
    ->name('webhooks.kapso');
```

Se carga automáticamente por el glob de `routes/api.php:7`.

#### `back/app/Console/Commands/KapsoWhatsappSendTestCommand.php`

`php artisan kapso:send-test {phone} {--template=program.created}`. Envía un mensaje real por Kapso sin necesidad de crear un programa desde la plataforma. Imprime el `wamid` para poder correlacionarlo con los webhooks que lleguen después.

Motivo: sin esto, cada iteración de prueba obliga a crear un programa completo en la UI. Acorta el ciclo de diagnóstico de minutos a segundos.

## Tests

### Unit

- `KapsoWhatsappGatewayTest` — con `Http::fake()`:
  - template → payload Meta correcto (nombre, `language.code`, parámetros en orden), `wamid` extraído de `messages.0.id`
  - texto libre → `type: text`
  - `4xx` → `DeliveryResult::failed`, **no** lanza
  - `5xx` → **lanza** (para que el job reintente)
  - `429` → lanza
  - template sin configurar → `RuntimeException`
  - orden con más de 9 variables (regresión del `ksort` numérico de DEC-04)
- `VerifyKapsoWebhookSignatureTest` — firma válida pasa; inválida da 401; body con unicode y barras (`"programa \"X\""`, acentos) valida bien contra el crudo; secreto ausente da 500.
- `RecordDeliveryStatusTest` — cada regla de DEC-07:
  - `sent` → `delivered` → `read` avanza
  - `delivered` seguido de `sent` tardío **no** retrocede
  - `Suppressed` nunca se sobreescribe
  - `failed` sobre `Delivered` se ignora
  - `failed` sobre `Sent` dispara el fallback
  - `provider_message_id` desconocido no rompe
- `ChannelFallbackServiceTest` — mueve los casos de fallback que hoy viven en `DeliverAlertJobTest`.
- Ajustar `ProgramCreatedMessageBuilderTest` y hermanos: ahora asertan `type` en vez de `templateId`.

### Feature

- `KapsoWebhookTest` — `POST /api/v1/webhooks/kapso`:
  - sin firma → 401
  - firma válida → 200 y fila en `whatsapp_webhook_events`
  - mismo `X-Idempotency-Key` dos veces → 200 y una sola fila
  - evento `delivered` end-to-end deja el `AlertRecipient` en `delivered` con `delivered_at`
  - evento `failed` end-to-end crea el recipient de email de fallback

Recordar las reglas de testing del proyecto: SQLite in-memory, `RefreshDatabase`, `WithoutModelEvents` en seeders y GUID explícito en factories (los eventos de modelo están deshabilitados, así que `guid` y los NOT NULL van seteados a mano).

## Prueba en entorno local

El obstáculo de local es que Kapso necesita alcanzar tu máquina por HTTPS público para entregar los webhooks. `http://localhost:8001` no le sirve.

1. Túnel público:
   ```bash
   ngrok http 8001
   ```
   Registrar la URL `https://xxxx.ngrok-free.app/api/v1/webhooks/kapso` en el dashboard de Kapso, suscribiendo `whatsapp.message.sent`, `.delivered`, `.read` y `.failed`. Copiar el Secret Key a `KAPSO_WEBHOOK_SECRET`.

2. `.env` local:
   ```env
   WHATSAPP_PROVIDER=kapso
   KAPSO_API_KEY=...
   KAPSO_PHONE_NUMBER_ID=...
   KAPSO_WEBHOOK_SECRET=...
   ```
   ```bash
   php artisan config:clear
   ```

3. Envío suelto para validar el gateway:
   ```bash
   php artisan kapso:send-test 5491134290838
   ```

4. Flujo completo, creando el programa desde la plataforma:
   ```bash
   php artisan schedule:work        # o: php artisan alerts:dispatch-due
   ```

5. Verificación — no mirar el inbox, mirar la base:
   ```sql
   SELECT channel, status, provider_message_id, failure_reason, sent_at, delivered_at
   FROM alert_recipients ORDER BY id DESC LIMIT 10;

   SELECT event_type, provider_message_id, processed_at, error
   FROM whatsapp_webhook_events ORDER BY id DESC LIMIT 20;
   ```

El fallback `whatsapp → email` hace que un fallo de WhatsApp llegue igual como mail. Si se valida por el inbox, un WhatsApp roto parece funcionar. La fila `whatsapp` en `status = sent/delivered` con `provider_message_id` cargado es la única prueba real.

## Riesgos

**R1 — La firma no valida por re-serialización del body.** Es el error más común de esta clase de integración. Si en algún punto se usa `json_encode($request->all())` en vez de `$request->getContent()`, la firma falla siempre y el síntoma (401 constante) parece un secreto mal copiado. Mitigación: el test con unicode y barras del middleware existe justamente para atrapar esto.

**R2 — Los eventos llegan desordenados.** Cubierto por la guarda de monotonía de DEC-07, pero es la parte del plan con más casos borde. Los tests de `RecordDeliveryStatus` no son opcionales.

**R3 — Regresión del refactor de `TemplateContent` sobre Twilio.** Toca los 3 builders, el gateway de Twilio y sus tests. Twilio debe seguir funcionando idéntico con `WHATSAPP_PROVIDER=twilio`. Verificación: correr la suite de notificaciones completa antes y después.

**R4 — Duplicación por reintentos de Kapso.** Kapso reintenta a los 10s/40s/90s si no recibe `200`. Sin el `unique` en `idempotency_key`, un handler lento generaría estados duplicados y potencialmente fallbacks duplicados. Mitigación: DEC-09 (persistir y responder rápido) + el `unique`.

**R5 — El `X-Idempotency-Key` podría no venir en todos los eventos.** La doc lo lista entre los headers, pero no garantiza que esté en el 100% de los eventos. Mitigación: el controlador sintetiza `sha256:<hash del body>` cuando falta. Nota: dos eventos legítimamente idénticos (mismo tipo, mismo mensaje, mismo contenido) se deduplicarían de más — aceptable, porque aplicar dos veces el mismo estado es idempotente por DEC-07.

**R6 — La doc de Kapso no publica el payload completo de `whatsapp.message.read`.** Los tipos de evento y los campos `message.id` / `message.to` están confirmados para `sent`, `delivered` y `failed`; para `read` la doc no muestra ejemplo. Mitigación: `RecordDeliveryStatus` lee defensivamente (`data_get($payload, 'message.id')`) y el evento crudo queda guardado en `whatsapp_webhook_events`, así que el primer `read` real que llegue permite ajustar el mapeo sin perder datos. La doc además advierte explícitamente: "Do not assume `phone_number`, `from`, `to`, or `wa_id`" están presentes en todos los eventos.

**R7 — Migrar de sandbox/Twilio a Kapso requiere recrear los templates.** Los templates de Kapso viven en la WABA de Kapso, no en Twilio. Los nombres (`sav_program_created`, etc.) y el orden de variables deben coincidir con lo que arma cada builder. Un template con las variables en otro orden produce mensajes con datos cruzados —"Hola Sincronización IATF, se creó el programa Lucas"— sin ningún error técnico. Verificación obligatoria con `kapso:send-test` antes de considerar la integración lista.

## Orden de implementación sugerido

1. Migraciones (índice + tabla de eventos) — sin dependencias.
2. Refactor de `TemplateContent` + 3 builders + gateway de Twilio + config. **Suite verde con `WHATSAPP_PROVIDER=twilio` antes de seguir.**
3. Normalización de teléfono en `toDto()` (DEC-05).
4. Extracción de `ChannelFallbackService` (DEC-08). Suite verde otra vez.
5. `KapsoWhatsappGateway` + binding + `kapso:send-test`. Validar envío real.
6. Middleware de firma + controlador + modelo + ruta. Validar con firma.
7. `RecordDeliveryStatus` + job de procesamiento. Validar transiciones.
8. Tests completos.

Los pasos 2, 3 y 4 son refactors que mejoran el código actual y no dependen de Kapso: pueden mergearse por separado, y conviene hacerlo para que el PR de Kapso quede acotado a lo nuevo.

## Extensiones futuras (fuera de alcance)

- Normalización de teléfono en escritura (mutator en `Contact` + migración de datos de los registros existentes).
- `WhatsappProviderResolver` para elegir proveedor por país/vet en runtime (DEC-02 no lo bloquea).
- Consumir `whatsapp.message.received` para cerrar el ciclo de `require_confirmation` / `confirmed_at`, columnas que ya existen en `alert_recipients` y que hoy nadie escribe.
- Reenvío de `whatsapp.conversation.*` al módulo de soporte.
