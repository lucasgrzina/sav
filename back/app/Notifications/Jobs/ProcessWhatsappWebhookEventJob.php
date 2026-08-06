<?php

namespace App\Notifications\Jobs;

use App\Notifications\Exceptions\UnsupportedWebhookEventException;
use App\Notifications\Models\WhatsappWebhookEvent;
use App\Notifications\Services\RecordDeliveryStatus;
use App\Notifications\Services\RecordInboundOptOut;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class ProcessWhatsappWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60];

    public function __construct(public int $eventId) {}

    public function handle(RecordDeliveryStatus $recorder, RecordInboundOptOut $inbound): void
    {
        $event = WhatsappWebhookEvent::find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return; // idempotencia: ya procesado
        }

        try {
            // An inbound message has no message.id correlatable to any AlertRecipient — it
            // is the id of the message the CLIENT sent, not one we sent — so it must be
            // routed away from RecordDeliveryStatus before it ever reaches it.
            $outcome = $event->event_type === 'whatsapp.message.received'
                ? $inbound->apply($event)
                : $recorder->apply($event);
        } catch (UnsupportedWebhookEventException $e) {
            // Definitivo: el mismo payload nunca va a volverse procesable, así que se cierra
            // con la explicación en lugar de reintentarse.
            $event->update([
                'processed_at' => now(),
                'error' => Str::limit($e->getMessage(), 250),
            ]);

            return;
        }

        $event->update([
            'processed_at' => now(),
            'outcome' => Str::limit($outcome, 250),
            'error' => null,
        ]);
    }

    /** El payload queda guardado igual: sin esto, un fallo de infra dejaría la fila sin explicación. */
    public function failed(Throwable $exception): void
    {
        WhatsappWebhookEvent::where('id', $this->eventId)->whereNull('processed_at')->update([
            'error' => Str::limit($exception->getMessage(), 250),
        ]);
    }
}
