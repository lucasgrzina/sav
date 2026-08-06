<?php

namespace App\Notifications\Services;

use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\UnsupportedWebhookEventException;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\WhatsappWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies one delivery-status webhook to its AlertRecipient.
 *
 * Webhooks are not ordered. The provider retries on any non-200 (10s / 40s / 90s), so a
 * retried `sent` can land after `delivered` arrived. Every transition here is therefore
 * monotonic — status only ever moves forward — and the terminal states are protected.
 */
final class RecordDeliveryStatus
{
    /** Transport progress only. Failed and Suppressed are terminal and deliberately absent. */
    private const PRECEDENCE = [
        'pending' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
    ];

    private const EVENT_STATUS = [
        'whatsapp.message.sent' => DeliveryStatus::Sent,
        'whatsapp.message.delivered' => DeliveryStatus::Delivered,
        'whatsapp.message.read' => DeliveryStatus::Read,
        'whatsapp.message.failed' => DeliveryStatus::Failed,

        // Twilio's MessageStatus vocabulary, translated to the same DeliveryStatus values.
        // "queued"/"sending" both mean only "the provider accepted it" — the same meaning as Sent.
        'twilio.message.queued' => DeliveryStatus::Sent,
        'twilio.message.sending' => DeliveryStatus::Sent,
        'twilio.message.sent' => DeliveryStatus::Sent,
        'twilio.message.delivered' => DeliveryStatus::Delivered,
        'twilio.message.read' => DeliveryStatus::Read,
        'twilio.message.failed' => DeliveryStatus::Failed,
        'twilio.message.undelivered' => DeliveryStatus::Failed,
    ];

    public function __construct(private readonly ChannelFallbackService $fallback) {}

    /**
     * @return string Short human-readable outcome, stored on the event row for diagnosis.
     * @throws UnsupportedWebhookEventException when the payload can never be applied.
     */
    public function apply(WhatsappWebhookEvent $event): string
    {
        $status = self::EVENT_STATUS[$event->event_type] ?? throw new UnsupportedWebhookEventException(
            "Tipo de evento no manejado: {$event->event_type}",
        );

        $wamid = $event->provider_message_id ?? data_get($event->payload, 'message.id');

        if (! is_string($wamid) || $wamid === '') {
            // Also the shape a buffered batch would take: several events, no single message.
            throw new UnsupportedWebhookEventException('Payload sin message.id aplicable.');
        }

        return DB::transaction(function () use ($event, $status, $wamid): string {
            // Two events for the same message arriving at once is normal, not exceptional.
            $recipient = AlertRecipient::query()
                ->where('provider_message_id', $wamid)
                ->lockForUpdate()
                ->first();

            if ($recipient === null) {
                return 'sin recipient para ese message id';
            }

            // An opt-out is the recipient's explicit decision about this channel, not a
            // transport state — no webhook may overwrite it.
            if ($recipient->status === DeliveryStatus::Suppressed) {
                return 'ignorado: recipient suprimido';
            }

            return $status === DeliveryStatus::Failed
                ? $this->applyFailure($recipient, $event)
                : $this->applyProgress($recipient, $status);
        });
    }

    private function applyFailure(AlertRecipient $recipient, WhatsappWebhookEvent $event): string
    {
        // A message already confirmed delivered or read cannot fail retroactively.
        if (in_array($recipient->status, [DeliveryStatus::Delivered, DeliveryStatus::Read], true)) {
            return 'ignorado: ya entregado';
        }

        // Escalating twice would send the fallback channel a duplicate message.
        if ($recipient->status === DeliveryStatus::Failed) {
            return 'ignorado: ya fallado';
        }

        $recipient->update([
            'status' => DeliveryStatus::Failed,
            'failure_reason' => $this->failureReason($event),
        ]);

        // The asynchronous failure path: the provider accepted the message (so the recipient
        // was already Sent) and only rejected it later. Without this the alert would sit in
        // Sent forever and the recipient would never hear about it.
        $this->fallback->attempt($recipient);

        return 'fallado, fallback disparado';
    }

    private function applyProgress(AlertRecipient $recipient, DeliveryStatus $status): string
    {
        // Failed is terminal: a fallback has already been dispatched, so resurrecting the
        // recipient would misreport a message the user was never going to get on this channel.
        if ($recipient->status === DeliveryStatus::Failed) {
            return 'ignorado: recipient ya fallado';
        }

        $current = self::PRECEDENCE[$recipient->status->value] ?? -1;

        if (self::PRECEDENCE[$status->value] <= $current) {
            return "ignorado: {$status->value} no supera {$recipient->status->value}";
        }

        $changes = ['status' => $status];

        // A lost `delivered` must not leave delivered_at empty on a message we know was read.
        if ($status === DeliveryStatus::Delivered || $status === DeliveryStatus::Read) {
            $changes['delivered_at'] = $recipient->delivered_at ?? now();
        }

        $recipient->update($changes);

        return "aplicado: {$status->value}";
    }

    /** Meta reports rejection details inside the message; the exact path varies by error. */
    private function failureReason(WhatsappWebhookEvent $event): string
    {
        $reason = data_get($event->payload, 'message.errors.0.title')
            ?? data_get($event->payload, 'message.errors.0.message')
            ?? data_get($event->payload, 'error.message')
            ?? data_get($event->payload, 'message.error')
            ?? 'reportado como fallido por el proveedor';

        $code = data_get($event->payload, 'message.errors.0.code');

        return Str::limit($code === null ? (string) $reason : "[{$code}] {$reason}", 250);
    }
}
