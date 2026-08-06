# Arquitectura de Notificaciones/Alertas (API Laravel 12)

Diseño del módulo de notificaciones/alertas para esta API. Es la **fuente de verdad del diseño**:
contratos, patrones y separación de responsabilidades. El **qué** (qué alertas existen, cuándo se
disparan y a quién) está en [`reglas-negocio-alertas.md`](./reglas-negocio-alertas.md).

> Proyecto greenfield: este módulo se construye desde cero. No hay implementación previa que migrar.

---

## 1. Principio rector

Separar **tres responsabilidades**, cada una detrás de un contrato:

```
QUÉ PASÓ                  →  QUÉ MENSAJE CONSTRUIR       →  CÓMO ENTREGARLO
(evento de dominio)          (builder por tipo)             (gateway por canal)

ProgramCancelled             ProgramCancelledBuilder        TwilioWhatsappGateway
HealthPlanMonth              HealthPlanMonthBuilder         VonageSmsGateway
EventReminder                EventReminderBuilder           MailGateway
```

Reglas que **no se negocian**:

- El tipo de alerta es un **enum `AlertType`**, NUNCA un FQCN de clase guardado en la DB.
- La relación al modelo origen usa **`morphTo` + morph map** (`'program'`, no `'App\Models\Program'`).
- El payload de cada alerta es un **DTO tipado** (spatie/laravel-data), no un JSON sin esquema.
- El proveedor de envío vive detrás del contrato **`NotificationChannelGateway`**; cambiar de
  proveedor = un adapter nuevo + una línea de config, sin tocar el dominio.
- Opt-out, quiet hours, dedup y rate-limit van en un **`DeliveryPipeline` de políticas**, NUNCA
  dentro del gateway.
- Un job **`DeliverAlertJob` por destinatario** (fan-out), con reintentos/backoff e idempotencia.
- Todo gateway tiene un **`FakeGateway`** para tests.

Stack asumido: Laravel 12, PHP 8.3+, `spatie/laravel-data`, una cola real (no `sync` en prod),
timezone `America/Argentina/Buenos_Aires`.

---

## 2. Enums

```php
namespace App\Notifications\Enums;

enum Channel: string
{
    case Whatsapp = 'whatsapp';
    case Sms      = 'sms';
    case Email    = 'email';
    case Push     = 'push';
}

enum AlertType: string
{
    case ProgramTaskDue   = 'program.task_due';
    case ProgramCreated   = 'program.created';
    case ProgramCancelled = 'program.cancelled';
    case HealthPlanMonth  = 'health_plan.month';
    case EventReminder    = 'event.reminder';
}

enum DeliveryStatus: string
{
    case Pending    = 'pending';    // creada, aún no enviada
    case Sent       = 'sent';       // aceptada por el proveedor
    case Delivered  = 'delivered';  // confirmada por webhook de estado
    case Read       = 'read';       // leída (receipt del proveedor)
    case Failed     = 'failed';     // falló definitivamente tras reintentos
    case Suppressed = 'suppressed'; // no se envió por política (opt-out, quiet hours, dedup)
}
```

---

## 3. Los contratos (el corazón de todo)

### 3.1 `NotificationChannelGateway` — abstracción sobre cualquier proveedor

```php
namespace App\Notifications\Contracts;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Enums\Channel;

interface NotificationChannelGateway
{
    public function channel(): Channel;

    /**
     * Envía y devuelve un resultado normalizado.
     * No lanza por fallo de negocio (número inválido, rechazo): eso viaja en DeliveryResult.
     * Solo lanza ante fallos transitorios (timeout, 5xx) para que la cola aplique backoff.
     */
    public function send(OutboundMessage $message): DeliveryResult;
}
```

### 3.2 `AlertMessageBuilder` — construye el contenido por tipo

```php
namespace App\Notifications\Contracts;

use App\Notifications\Enums\AlertType;
use App\Notifications\Data\{Recipient, MessageContent};
use App\Notifications\Models\Alert;

interface AlertMessageBuilder
{
    public function type(): AlertType;

    public function build(Alert $alert, Recipient $recipient): MessageContent;
}
```

### 3.3 `DeliveryPolicy` — política transversal previa al envío

```php
namespace App\Notifications\Contracts;

use App\Notifications\Data\{OutboundMessage, SuppressionReason};

interface DeliveryPolicy
{
    /** null si puede seguir; un motivo si debe frenarse. */
    public function check(OutboundMessage $message): ?SuppressionReason;
}
```

### 3.4 `MessageContent` — jerarquía sellada

```php
namespace App\Notifications\Data;

interface MessageContent {}

// Template pre-aprobado (WhatsApp Business exige esto para mensajes proactivos)
final readonly class TemplateContent implements MessageContent
{
    public function __construct(
        public string $templateId,   // "contentSid" en Twilio, "name" en Cloud API
        /** @var array<string,string> */
        public array  $variables,    // ["1" => "...", "2" => "..."]
        public string $locale = 'es_AR',
    ) {}
}

// Texto libre (SMS / email / canales sin template obligatorio)
final readonly class TextContent implements MessageContent
{
    public function __construct(public string $body) {}
}
```

---

## 4. DTOs / Value Objects

Con `spatie/laravel-data` para casteo y validación.

```php
namespace App\Notifications\Data;

use App\Notifications\Enums\Channel;

final readonly class Recipient
{
    public function __construct(
        public int     $userId,
        public string  $phone,    // E.164 sin '+', normalizado
        public string  $name,
        public Channel $channel,
        public ?string $email = null,
    ) {}
}

final readonly class OutboundMessage
{
    public function __construct(
        public Recipient      $recipient,
        public MessageContent $content,
        public Channel        $channel,
        public string         $idempotencyKey,
    ) {}
}

final readonly class DeliveryResult
{
    private function __construct(
        public DeliveryStatus $status,
        public ?string        $providerMessageId = null,
        public ?string        $failureReason = null,
    ) {}

    public static function sent(string $providerMessageId): self
    {
        return new self(DeliveryStatus::Sent, providerMessageId: $providerMessageId);
    }

    public static function failed(string $reason): self
    {
        return new self(DeliveryStatus::Failed, failureReason: $reason);
    }

    public static function suppressed(SuppressionReason $reason): self
    {
        return new self(DeliveryStatus::Suppressed, failureReason: $reason->value);
    }
}
```

### Payloads tipados por tipo de alerta

Cada `AlertType` tiene su DTO validado, casteado desde la columna `payload`. Ver los payloads
concretos (mensajes de tarea, actividades del mes, etc.) en `reglas-negocio-alertas.md`.

```php
namespace App\Notifications\Data\Payloads;

use Spatie\LaravelData\Data;

final class ProgramTaskPayload extends Data
{
    public function __construct(
        /** @var TaskMessage[] */
        public array   $messages,
        public ?string $headerDay = null,
    ) {}
}

final class TaskMessage extends Data
{
    public function __construct(public string $message) {}
}
```

---

## 5. Modelo de datos

```php
// alerts — la INTENCIÓN de notificar
Schema::create('alerts', function (Blueprint $t) {
    $t->id();
    $t->string('type');                    // AlertType enum, NO un FQCN
    $t->nullableMorphs('subject');         // subject_type usa MORPH MAP ('program'), no FQCN
    $t->json('payload');                   // validado por un Data DTO
    $t->timestamp('scheduled_at');
    $t->string('status')->default('pending');
    $t->boolean('require_confirmation')->default(false);
    $t->foreignId('vet_id')->nullable()->constrained(); // multi-tenant desde el día 1
    $t->timestamps();
    $t->index(['status', 'scheduled_at']);
});

// alert_recipients — tracking POR destinatario y POR canal
Schema::create('alert_recipients', function (Blueprint $t) {
    $t->id();
    $t->foreignId('alert_id')->constrained()->cascadeOnDelete();
    $t->foreignId('user_id')->constrained();
    $t->string('channel');                          // Channel enum
    $t->string('status')->default('pending');       // DeliveryStatus enum
    $t->string('provider_message_id')->nullable();  // para casar el webhook de estado
    $t->unsignedTinyInteger('attempts')->default(0);
    $t->string('failure_reason')->nullable();
    $t->timestamp('sent_at')->nullable();
    $t->timestamp('delivered_at')->nullable();
    $t->timestamp('confirmed_at')->nullable();
    $t->string('idempotency_key')->unique();
    $t->timestamps();
    $t->unique(['alert_id', 'user_id', 'channel']);
});

// opt_outs — centralizado, por canal
Schema::create('opt_outs', function (Blueprint $t) {
    $t->id();
    $t->string('phone');            // E.164 sin '+'
    $t->string('channel');
    $t->timestamp('created_at')->nullable();
    $t->unique(['phone', 'channel']);
});
```

**Morph map** en un `ServiceProvider` (la DB guarda `'program'`, no el FQCN):

```php
Relation::enforceMorphMap([
    'program'     => \App\Models\Program::class,
    'health_plan' => \App\Models\HealthPlan::class,
    'event'       => \App\Models\Event::class,
]);
```

---

## 6. Patrones de diseño y dónde cae cada uno

| Patrón | Dónde | Qué resuelve |
|---|---|---|
| **Strategy** | `AlertMessageBuilder` por tipo · `NotificationChannelGateway` por canal | Agregar tipos/proveedores sin `switch` |
| **Registry / Factory** | `MessageBuilderRegistry`, `GatewayRegistry` | Resolver builder/gateway por enum |
| **Adapter** | Cada gateway envuelve el SDK del proveedor | Aislar Twilio/Vonage/Meta detrás del contrato |
| **Chain of Responsibility (Pipeline)** | `DeliveryPipeline` de policies | Opt-out, quiet hours, dedup, rate-limit componibles |
| **Value Object / DTO** | `OutboundMessage`, `Recipient`, `MessageContent`, `DeliveryResult` | Datos inmutables y tipados |
| **Domain Events + Listener** | `ProgramCancelled` → `ScheduleAlertsListener` | Desacoplar el origen de la creación de alertas |
| **Command + Queue** | `DeliverAlertJob` por destinatario | Reintentos, backoff, aislamiento de fallos |
| **State machine** | `DeliveryStatus` sobre `alert_recipients` | Ciclo de vida explícito y auditable |

---

## 7. Registries

```php
namespace App\Notifications\Registries;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Enums\Channel;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class GatewayRegistry
{
    /** @var array<string, class-string<NotificationChannelGateway>> */
    private array $map;

    public function __construct(
        private readonly Container $container,
        array $config, // config('notifications.channels')
    ) {
        $this->map = collect($config)
            ->mapWithKeys(fn ($c, $channel) => [$channel => $c['gateway']])
            ->all();
    }

    public function for(Channel $channel): NotificationChannelGateway
    {
        $class = $this->map[$channel->value]
            ?? throw new InvalidArgumentException("Sin gateway para canal {$channel->value}");

        return $this->container->make($class);
    }
}
```

`MessageBuilderRegistry` es análogo (mapea `AlertType` → `AlertMessageBuilder`). Los builders se
autoregistran con un **tagged binding**:

```php
// NotificationServiceProvider::register()
$this->app->tag([
    ProgramTaskMessageBuilder::class,
    ProgramCreatedMessageBuilder::class,
    ProgramCancelledMessageBuilder::class,
    HealthPlanMonthMessageBuilder::class,
    EventReminderMessageBuilder::class,
], 'alert.builders');

$this->app->singleton(MessageBuilderRegistry::class, function ($app) {
    return new MessageBuilderRegistry(iterator_to_array($app->tagged('alert.builders')));
});
```

---

## 8. Pipeline de políticas (opt-out transversal, no dentro del gateway)

```php
namespace App\Notifications\Pipeline;

use App\Notifications\Contracts\DeliveryPolicy;
use App\Notifications\Data\{OutboundMessage, SuppressionReason};

final class DeliveryPipeline
{
    /** @param iterable<DeliveryPolicy> $policies */
    public function __construct(private readonly iterable $policies) {}

    public function run(OutboundMessage $message): ?SuppressionReason
    {
        foreach ($this->policies as $policy) {
            if ($reason = $policy->check($message)) {
                return $reason; // primera política que frena, gana
            }
        }
        return null;
    }
}
```

```php
namespace App\Notifications\Policies;

use App\Notifications\Contracts\DeliveryPolicy;
use App\Notifications\Data\{OutboundMessage, SuppressionReason};
use App\Notifications\Models\OptOut;

final class OptOutPolicy implements DeliveryPolicy
{
    public function check(OutboundMessage $message): ?SuppressionReason
    {
        $optedOut = OptOut::query()
            ->where('phone', $message->recipient->phone)
            ->where('channel', $message->channel->value)
            ->exists();

        return $optedOut ? SuppressionReason::OptedOut : null;
    }
}
```

Otras políticas: `QuietHoursPolicy` (respeta `America/Argentina/Buenos_Aires`),
`DuplicateSuppressionPolicy` (usa el `idempotencyKey`), `RateLimitPolicy` (por proveedor/número).

---

## 9. Ejemplo end-to-end: un builder + un gateway completos

### 9.1 Builder (Strategy)

```php
namespace App\Notifications\Builders;

use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\{MessageContent, Recipient, TemplateContent};
use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Models\Program;
use Illuminate\Support\Carbon;

final class ProgramTaskMessageBuilder implements AlertMessageBuilder
{
    /** Cantidad de tareas → id de template aprobado. (Ver catálogo en reglas-negocio-alertas.md) */
    private const TEMPLATES = [
        1 => 'HXc36e957f1fb2e80045823abb257add3b',
        2 => 'HXabf7c5e912af71c2a52ff175fae79563',
        3 => 'HXf624fb6bcaccd55c849c5ebfb8348a0c',
        4 => 'HX888c721be28db9ca32dbeb445ee5db8c',
        5 => 'HX37d87fd15c92ddb0602a6deb8a31f2e6',
    ];

    public function type(): AlertType
    {
        return AlertType::ProgramTaskDue;
    }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject; // morphTo real, sin FQCN a mano
        $payload = ProgramTaskPayload::from($alert->payload);

        $variables = [
            '1' => $program->client->name,
            '2' => $recipient->name,
            '3' => $this->resolveHeaderDate($payload->headerDay),
        ];

        $i = 4;
        foreach ($payload->messages as $msg) {
            $variables[(string) $i++] = $msg->message;
        }
        $variables[(string) $i++] = $program->name;
        $variables[(string) $i]   = $program->technique->target_date_name
            . ': ' . Carbon::parse($program->target_date)->format('d-m-Y');

        return new TemplateContent(
            templateId: self::TEMPLATES[count($payload->messages)],
            variables:  $variables,
        );
    }

    private function resolveHeaderDate(?string $headerDay): string
    {
        return match (true) {
            $headerDay === null                     => 'Mensaje:',
            str_contains($headerDay, '{{fecha+1}}') => str_replace('{{fecha+1}}', Carbon::tomorrow()->format('d-m-Y'), $headerDay),
            str_contains($headerDay, '{{fecha}}')   => str_replace('{{fecha}}', Carbon::now()->format('d-m-Y'), $headerDay),
            default                                  => $headerDay,
        };
    }
}
```

### 9.2 Gateway (Adapter)

```php
namespace App\Notifications\Gateways\Twilio;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\{DeliveryResult, OutboundMessage, TemplateContent, TextContent};
use App\Notifications\Enums\Channel;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

final class TwilioWhatsappGateway implements NotificationChannelGateway
{
    public function __construct(
        private readonly Client $twilio,   // inyectado, NO `new Client(env(...))`
        private readonly string $from,     // config('notifications.twilio.messaging_service')
    ) {}

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $to = 'whatsapp:+' . $message->recipient->phone;

        try {
            $sent = match (true) {
                $message->content instanceof TemplateContent => $this->twilio->messages->create($to, [
                    'from'             => $this->from,
                    'contentSid'       => $message->content->templateId,
                    'contentVariables' => json_encode($message->content->variables),
                ]),
                $message->content instanceof TextContent => $this->twilio->messages->create($to, [
                    'from' => $this->from,
                    'body' => $message->content->body,
                ]),
            };

            return DeliveryResult::sent($sent->sid);
        } catch (RestException $e) {
            if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                return DeliveryResult::failed($e->getMessage()); // 4xx: no reintentar
            }
            throw $e; // 5xx / timeout: relanza para backoff de la cola
        }
    }
}
```

### 9.3 Config (aísla credenciales, habilita el swap)

```php
// config/notifications.php
return [
    'channels' => [
        'whatsapp' => ['gateway' => \App\Notifications\Gateways\Twilio\TwilioWhatsappGateway::class],
        'sms'      => ['gateway' => \App\Notifications\Gateways\Vonage\VonageSmsGateway::class],
        'email'    => ['gateway' => \App\Notifications\Gateways\Mail\MailGateway::class],
    ],
    'fallback' => [
        'whatsapp' => ['sms', 'email'], // si WhatsApp falla, cae a SMS y luego email
    ],
    'twilio' => [
        'sid'               => env('TWILIO_ACCOUNT_SID'),
        'token'             => env('TWILIO_AUTH_TOKEN'),
        'messaging_service' => env('TWILIO_TEMPLATE_MESSAGE_SERVICE'),
    ],
];
```

```php
// NotificationServiceProvider::register()
$this->app->singleton(TwilioWhatsappGateway::class, function ($app) {
    $cfg = config('notifications.twilio');
    return new TwilioWhatsappGateway(
        new \Twilio\Rest\Client($cfg['sid'], $cfg['token']),
        $cfg['messaging_service'],
    );
});
```

---

## 10. Flujo end-to-end

```
1. Ocurre algo de dominio
   CancelProgramAction  ──dispara──▶  event(new ProgramCancelled($program))

2. Listener (desacoplado del origen)
   ScheduleAlertsListener  crea  Alert(type: ProgramCancelled) + AlertRecipient[]  con scheduled_at

3. Scheduler (cada minuto)  →  DispatchDueAlerts::handle()
   query: status=pending AND scheduled_at <= now()
   por cada recipient  ──▶  dispatch(new DeliverAlertJob($recipientId))   ← FAN-OUT

4. DeliverAlertJob (por destinatario; $tries=5, $backoff=[60,300,900,1800])
   builder = builderRegistry->for($alert->type)
   content = builder->build($alert, $recipient)
   message = new OutboundMessage($recipient, $content, $channel, $idempotencyKey)
   suppression = pipeline->run($message)          ← opt-out, quiet hours, dedup, rate-limit
   result  = gatewayRegistry->for($channel)->send($message)
   recipient->update(status, provider_message_id, sent_at)

5. Webhook del proveedor (asíncrono)
   TwilioWebhookController normaliza → event(DeliveryStatusUpdated | RecipientOptedOut)
   → actualiza alert_recipients.status = delivered/read/failed
   → o crea/borra opt_out
```

### El job de entrega

```php
namespace App\Notifications\Jobs;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Registries\{GatewayRegistry, MessageBuilderRegistry};
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Enums\DeliveryStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

final class DeliverAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    /** @var int[] */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public int $recipientId) {}

    public function handle(
        MessageBuilderRegistry $builders,
        GatewayRegistry $gateways,
        DeliveryPipeline $pipeline,
    ): void {
        $recipient = AlertRecipient::with('alert.subject')->findOrFail($this->recipientId);

        if ($recipient->status !== DeliveryStatus::Pending) {
            return; // idempotencia: ya procesado
        }

        $content = $builders->for($recipient->alert->type)->build($recipient->alert, $recipient->toDto());
        $message = new OutboundMessage(
            $recipient->toDto(), $content, $recipient->channel, $recipient->idempotency_key,
        );

        if ($reason = $pipeline->run($message)) {
            $recipient->update(['status' => DeliveryStatus::Suppressed, 'failure_reason' => $reason->value]);
            return;
        }

        $result = $gateways->for($recipient->channel)->send($message);

        $recipient->update([
            'status'              => $result->status,
            'provider_message_id' => $result->providerMessageId,
            'failure_reason'      => $result->failureReason,
            'sent_at'             => now(),
            'attempts'            => $recipient->attempts + 1,
        ]);
    }
}
```

---

## 11. Webhook entrante (opt-out/opt-in y estados de entrega)

El controlador solo **normaliza** el payload del proveedor a eventos de dominio; la lógica vive en
listeners.

```php
namespace App\Notifications\Webhooks;

use App\Notifications\Events\{RecipientOptedOut, RecipientOptedIn, DeliveryStatusUpdated};
use App\Notifications\Enums\Channel;
use Illuminate\Http\Request;

final class TwilioWebhookController
{
    public function __invoke(Request $request)
    {
        // Mensajes entrantes (BAJA / ALTA)
        if ($body = $request->input('Body')) {
            $phone = $this->normalize($request->input('WaId'));
            match (trim(strtolower($body))) {
                'baja'  => event(new RecipientOptedOut($phone, Channel::Whatsapp)),
                'alta'  => event(new RecipientOptedIn($phone, Channel::Whatsapp)),
                default => null,
            };
        }

        // Callbacks de estado de entrega
        if ($sid = $request->input('MessageSid')) {
            event(new DeliveryStatusUpdated($sid, $request->input('MessageStatus')));
        }

        return response()->noContent();
    }

    /** WhatsApp AR manda 549..., el número real es 54... (quita el 9). */
    private function normalize(string $waId): string
    {
        return str_starts_with($waId, '549') ? '54' . substr($waId, 3) : $waId;
    }
}
```

---

## 12. Un paso adelante (dejarlo previsto desde el diseño)

1. **Fallback de canales por destinatario** (Chain of Responsibility): WhatsApp → SMS → email según
   `config('notifications.fallback')`.
2. **Preferencias de canal por usuario**: tabla `user_notification_preferences`.
3. **Read/delivery receipts reales** vía webhook de estado → poblar `delivered_at`/`Read`.
4. **Outbox pattern**: crear el `Alert` en la misma transacción que el cambio de dominio.
5. **Testing con fakes**: `FakeGateway` que registra envíos → tests de feature sin tocar Twilio.
6. **Localización**: `locale` ya viaja en `TemplateContent`.
7. **Multi-tenancy desde el día 1**: `vet_id` en `alerts`, scoping por tenant en el scheduler.

---

## 13. Estructura de carpetas

```
app/Notifications/
├── Contracts/          NotificationChannelGateway, AlertMessageBuilder, DeliveryPolicy
├── Enums/              Channel, AlertType, DeliveryStatus
├── Models/             Alert, AlertRecipient, OptOut
├── Data/               OutboundMessage, Recipient, DeliveryResult, SuppressionReason,
│                       MessageContent/ (TemplateContent, TextContent), Payloads/
├── Builders/           ProgramTaskMessageBuilder, ProgramCancelledMessageBuilder, ...
├── Gateways/           Twilio/, WhatsappCloud/, Vonage/, Mail/          ← adapters
├── Policies/           OptOutPolicy, QuietHoursPolicy, RateLimitPolicy, DuplicateSuppressionPolicy
├── Pipeline/           DeliveryPipeline
├── Scheduling/         ScheduleAlertsListener, DispatchDueAlerts (command)
├── Jobs/               DeliverAlertJob
├── Registries/         MessageBuilderRegistry, GatewayRegistry
├── Events/             ProgramCancelled, RecipientOptedOut, DeliveryStatusUpdated, ...
├── Webhooks/           TwilioWebhookController
└── NotificationServiceProvider.php
```

---

## 14. Orden de implementación

Cada paso debe quedar testeable de punta a punta:

1. **Enums + contratos + DTOs** (sin dependencias).
2. **Migraciones + modelos** (`Alert`, `AlertRecipient`, `OptOut`) con morph map.
3. **Un builder + `TwilioWhatsappGateway` + `FakeGateway`** con tests.
4. **`DeliveryPipeline`** empezando por `OptOutPolicy`.
5. **`DeliverAlertJob` + `DispatchDueAlerts`**, y recién ahí conectar los domain events de cada
   origen según [`reglas-negocio-alertas.md`](./reglas-negocio-alertas.md).
