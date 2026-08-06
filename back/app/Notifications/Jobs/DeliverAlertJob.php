<?php

namespace App\Notifications\Jobs;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Exceptions\RecipientContactNotFoundException;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Registries\GatewayRegistry;
use App\Notifications\Registries\MessageBuilderRegistry;
use App\Notifications\Services\ChannelFallbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    ): void {
        $recipient = AlertRecipient::with('alert.subject')->findOrFail($this->recipientId);

        if ($recipient->status !== DeliveryStatus::Pending) {
            return; // idempotencia: ya procesado
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
            ]);
            $fallback->attempt($recipient);

            return;
        }

        $content = $builders->for($recipient->alert->type)->build($recipient->alert, $recipientDto);
        $message = new OutboundMessage(
            $recipientDto, $content, $recipient->channel, $recipient->idempotency_key,
        );

        if ($reason = $pipeline->run($message)) {
            // Suprimido (ej. opt-out) nunca cae a un canal alternativo: es una decisión
            // explícita del destinatario sobre ESTE canal, no una falla técnica.
            $recipient->update(['status' => DeliveryStatus::Suppressed, 'failure_reason' => $reason->value]);

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
                'attempts' => $recipient->attempts + 1,
            ]);
            $fallback->attempt($recipient);

            return;
        }

        $recipient->update([
            'status' => $result->status,
            'provider_message_id' => $result->providerMessageId,
            'failure_reason' => $result->failureReason,
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
            'attempts' => $recipient->attempts + 1,
        ]);

        // Los hooks de fallo del job no reciben inyección por firma.
        app(ChannelFallbackService::class)->attempt($recipient);
    }
}
