<?php

namespace App\Notifications\Jobs;

use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Exceptions\RecipientContactNotFoundException;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Registries\AlertRevalidatorRegistry;
use App\Notifications\Registries\GatewayRegistry;
use App\Notifications\Registries\MessageBuilderRegistry;
use App\Notifications\Services\ChannelFallbackService;
use App\Notifications\Services\FailureClassifier;
use App\Notifications\Services\ServiceMisconfiguredNotifier;
use App\Notifications\Support\AlertsSchemaGuard;
use App\Notifications\Support\PhoneMasker;
use App\Notifications\Support\PhoneValidator;
use App\Notifications\Templates\WhatsappTemplateCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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
        ChannelFallbackService $fallback,
        ?AlertRevalidatorRegistry $revalidators = null,
        ?AlertsSchemaGuard $schema = null,
    ): void {
        // Code deployed before the Fase 3 SQL: every write below would hit an unknown column. Keep the
        // recipient untouched (still pending, re-queueable) and retry later instead of burning the attempt.
        $schema ??= app(AlertsSchemaGuard::class);
        if (! $schema->isReady()) {
            Log::critical('DeliverAlertJob: alerts schema is out of date, delivery postponed', [
                'recipient_id' => $this->recipientId,
                'missing' => $schema->missing(),
            ]);
            $this->release(300);

            return;
        }

        $recipient = AlertRecipient::with('alert.subject')->find($this->recipientId);

        if ($recipient === null) {
            // El destinatario fue eliminado (ej. se borró el perfil o la alerta) entre el despacho y
            // este intento: no hay nada que enviar y reintentar nunca lo arregla.
            Log::info('DeliverAlertJob: destinatario inexistente, se descarta', [
                'recipient_id' => $this->recipientId,
            ]);

            return;
        }

        if ($recipient->status !== DeliveryStatus::Pending) {
            return; // idempotencia: ya procesado
        }

        if ($recipient->alert->status === AlertStatus::Cancelled->value) {
            // La alerta se canceló después de despacharse: se suprime sin fallback.
            $recipient->update([
                'status' => DeliveryStatus::Suppressed,
                'failure_reason' => 'no_longer_applicable',
                'failure_code' => FailureCode::NoLongerApplicable,
            ]);

            return;
        }

        // Runs on every attempt (queue retries and the email fallback included), so a program
        // cancelled or a responsible removed after dispatch stops the send. No fallback: the
        // message no longer applies, it did not fail.
        $revalidators ??= app(AlertRevalidatorRegistry::class);
        $code = $revalidators->for($recipient->alert->type)?->check($recipient->alert, $recipient);

        if ($code !== null) {
            $recipient->update([
                'status' => DeliveryStatus::Suppressed,
                'failure_reason' => 'no_longer_applicable',
                'failure_code' => $code,
            ]);

            return;
        }

        try {
            $recipientDto = $recipient->toDto();
        } catch (RecipientContactNotFoundException $e) {
            // Falta de dato de contacto, no un problema de infraestructura: el perfil no
            // tiene (o dejó de tener) un contacto habilitado para este canal.
            Log::warning('DeliverAlertJob: contacto no encontrado para el destinatario', [
                'alert_id' => $recipient->alert_id,
                'recipient_id' => $recipient->id,
                'channel' => $recipient->channel->value,
                'alert_type' => $recipient->alert->type->value,
                'error' => $e->getMessage(),
            ]);

            $recipient->update([
                'status' => DeliveryStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'failure_code' => app(FailureClassifier::class)->fromThrowable($e, $recipient->channel),
            ]);
            $fallback->attempt($recipient);

            return;
        }

        $destinationHint = $this->destinationHint($recipientDto);

        // Mismo validador que usa el preview: un número implausible no llega al proveedor.
        if ($recipient->channel === Channel::Whatsapp && ! PhoneValidator::isPlausible((string) $recipientDto->phone)) {
            $recipient->update([
                'status' => DeliveryStatus::Failed,
                'failure_reason' => 'Número de teléfono no válido',
                'failure_code' => FailureCode::InvalidNumber,
                'destination_hint' => $destinationHint,
            ]);
            $fallback->attempt($recipient);

            return;
        }

        $content = $builders->for($recipient->alert->type)->build($recipient->alert, $recipientDto);

        if ($content === null) {
            // El builder recalculó el contenido en destino (ver HealthPlanMonthMessageBuilder) y
            // determinó que la alerta ya no aplica (ej. todas las actividades se confirmaron entre
            // la creación de la Alert y este envío). No es un fallo técnico: no hay fallback.
            $recipient->update([
                'status' => DeliveryStatus::Suppressed,
                'failure_reason' => 'no_longer_applicable',
                'failure_code' => FailureCode::NoLongerApplicable,
                'destination_hint' => $destinationHint,
            ]);

            return;
        }

        $message = new OutboundMessage(
            $recipientDto, $content, $recipient->channel, $recipient->idempotency_key,
        );

        // Trazabilidad: qué número y qué texto se intentaron, incluso si el envío falla o se suprime.
        $recipient->update([
            'destination_hint' => $destinationHint,
            'rendered_body' => $this->renderedBody($content),
            'template_version' => $content instanceof TemplateContent ? $content->version : null,
        ]);

        if ($reason = $pipeline->run($message)) {
            // Suprimido (ej. opt-out) nunca cae a un canal alternativo: es una decisión
            // explícita del destinatario sobre ESTE canal, no una falla técnica.
            $recipient->update([
                'status' => DeliveryStatus::Suppressed,
                'failure_reason' => $reason->value,
                'failure_code' => FailureCode::tryFrom($reason->value) ?? FailureCode::Unknown,
            ]);

            return;
        }

        try {
            $result = $gateways->for($recipient->channel)->send($message);
        } catch (NotificationConfigurationException $e) {
            // Definitivo por naturaleza: reintentar con backoff nunca arregla un template
            // sin configurar ni una credencial faltante, y dejarlo escalar mantendría el
            // alert pendiente ~50 minutos antes de caer al canal de fallback.
            // Requiere intervención de un operador (ej. WHATSAPP_PROVIDER inválido o
            // credencial vencida), no es un problema transitorio de entrega.
            Log::error('DeliverAlertJob: gateway de notificaciones mal configurado', [
                'alert_id' => $recipient->alert_id,
                'recipient_id' => $recipient->id,
                'channel' => $recipient->channel->value,
                'alert_type' => $recipient->alert->type->value,
                'error' => $e->getMessage(),
            ]);

            $recipient->update([
                'status' => DeliveryStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'failure_code' => FailureCode::ServiceMisconfigured,
                'attempts' => $recipient->attempts + 1,
            ]);
            app(ServiceMisconfiguredNotifier::class)->report($recipient, $e);
            $fallback->attempt($recipient);

            return;
        }

        $recipient->update([
            'status' => $result->status,
            'provider_message_id' => $result->providerMessageId,
            'failure_reason' => $result->failureReason,
            'failure_code' => $result->status === DeliveryStatus::Failed
                ? ($result->failureCode ?? FailureCode::Unknown)
                : $result->failureCode,
            'sent_at' => now(),
            'attempts' => $recipient->attempts + 1,
        ]);

        if ($result->status === DeliveryStatus::Failed) {
            $fallback->attempt($recipient);
        }
    }

    /** Se ejecuta una sola vez, cuando la cola agota los $tries reintentos de un fallo transitorio (5xx/timeout). */
    public function failed(Throwable $exception): void
    {
        // Must never throw (it runs after the queue gave up): with an outdated schema the recipient is
        // left pending on purpose so it can be re-queued once the SQL is applied (see the SQL-01 runbook).
        try {
            if (! app(AlertsSchemaGuard::class)->isReady()) {
                Log::critical('DeliverAlertJob: retries exhausted with an outdated alerts schema, recipient left pending', [
                    'recipient_id' => $this->recipientId,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            $recipient = AlertRecipient::find($this->recipientId);

            if ($recipient === null || $recipient->status !== DeliveryStatus::Pending) {
                return;
            }

            Log::error('DeliverAlertJob: reintentos agotados, fallo definitivo', [
                'alert_id' => $recipient->alert_id,
                'recipient_id' => $recipient->id,
                'channel' => $recipient->channel->value,
                'alert_type' => $recipient->alert?->type?->value,
                'error' => $exception->getMessage(),
            ]);

            $recipient->update([
                'status' => DeliveryStatus::Failed,
                'failure_reason' => $exception->getMessage(),
                'failure_code' => FailureCode::ProviderUnreachable,
                'attempts' => $recipient->attempts + 1,
            ]);

            // Los hooks de fallo del job no reciben inyección por firma.
            app(ChannelFallbackService::class)->attempt($recipient);
        } catch (Throwable $e) {
            Log::critical('DeliverAlertJob: failed() hook could not record the failure', [
                'recipient_id' => $this->recipientId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Masked destination (never the raw phone/email); null for push, which has no address. */
    private function destinationHint(Recipient $recipient): ?string
    {
        return match ($recipient->channel) {
            Channel::Email => $recipient->email !== null ? PhoneMasker::maskEmail($recipient->email) : null,
            Channel::Push => null,
            default => $recipient->phone !== null && $recipient->phone !== '' ? PhoneMasker::mask($recipient->phone) : null,
        };
    }

    /** The text the recipient sees; null for push and for template types without catalog copy. */
    private function renderedBody(MessageContent $content): ?string
    {
        return match (true) {
            $content instanceof TemplateContent => $this->renderTemplate($content),
            $content instanceof EmailContent, $content instanceof TextContent => $content->body,
            default => null,
        };
    }

    private function renderTemplate(TemplateContent $content): ?string
    {
        try {
            return WhatsappTemplateCatalog::render($content->type, $content->version, $content->variables);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
