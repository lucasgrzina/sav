<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\UnsupportedWebhookEventException;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\WhatsappWebhookEvent;
use App\Notifications\Services\ChannelFallbackService;
use App\Notifications\Services\RecordDeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesNotificationFixtures;
use Tests\TestCase;

class RecordDeliveryStatusTest extends TestCase
{
    use CreatesNotificationFixtures, RefreshDatabase;

    private const WAMID = 'wamid.HBgNMTU1NTE0OTU5Nzg1';

    private function recorder(): RecordDeliveryStatus
    {
        return new RecordDeliveryStatus(new ChannelFallbackService());
    }

    /** @param array<string, mixed> $payload */
    private function event(string $type, ?array $payload = null): WhatsappWebhookEvent
    {
        return WhatsappWebhookEvent::create([
            'provider' => 'kapso',
            'idempotency_key' => Str::uuid()->toString(),
            'event_type' => $type,
            'provider_message_id' => self::WAMID,
            'payload' => $payload ?? ['type' => $type, 'message' => ['id' => self::WAMID]],
        ]);
    }

    private function recipientWith(DeliveryStatus $status): AlertRecipient
    {
        return $this->createRecipient(
            $this->createManagerProfile(),
            $this->createAlert(),
            Channel::Whatsapp,
            $status,
            self::WAMID,
        );
    }

    public function test_advances_through_sent_delivered_and_read(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Pending);

        $this->assertSame('aplicado: sent', $this->recorder()->apply($this->event('whatsapp.message.sent')));
        $this->assertSame('aplicado: delivered', $this->recorder()->apply($this->event('whatsapp.message.delivered')));
        $this->assertSame('aplicado: read', $this->recorder()->apply($this->event('whatsapp.message.read')));

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Read, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
    }

    /** Providers retry, so a repeated `sent` can land after `delivered` already arrived. */
    public function test_a_late_sent_event_does_not_regress_a_delivered_recipient(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Delivered);

        $outcome = $this->recorder()->apply($this->event('whatsapp.message.sent'));

        $this->assertSame('ignorado: sent no supera delivered', $outcome);
        $this->assertSame(DeliveryStatus::Delivered, $recipient->refresh()->status);
    }

    public function test_a_repeated_delivered_event_is_a_no_op(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Delivered);
        $recipient->update(['delivered_at' => now()->subHour()]);
        $original = $recipient->fresh()->delivered_at;

        $this->recorder()->apply($this->event('whatsapp.message.delivered'));

        $this->assertTrue($original->equalTo($recipient->fresh()->delivered_at));
    }

    /** A lost `delivered` must not leave delivered_at empty on a message we know was read. */
    public function test_read_backfills_delivered_at_when_delivered_was_never_received(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $this->recorder()->apply($this->event('whatsapp.message.read'));

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Read, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
    }

    /** An opt-out is the recipient's decision about the channel, not a transport state. */
    public function test_a_suppressed_recipient_is_never_overwritten(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Suppressed);

        foreach (['sent', 'delivered', 'read', 'failed'] as $event) {
            $outcome = $this->recorder()->apply($this->event("whatsapp.message.{$event}"));
            $this->assertSame('ignorado: recipient suprimido', $outcome);
        }

        $this->assertSame(DeliveryStatus::Suppressed, $recipient->refresh()->status);
    }

    public function test_a_delivered_message_cannot_fail_retroactively(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Delivered);

        $outcome = $this->recorder()->apply($this->event('whatsapp.message.failed'));

        $this->assertSame('ignorado: ya entregado', $outcome);
        $this->assertSame(DeliveryStatus::Delivered, $recipient->refresh()->status);
    }

    /** The asynchronous failure path: accepted with a wamid, rejected minutes later. */
    public function test_a_failure_after_send_marks_failed_and_escalates_to_email(): void
    {
        Queue::fake();

        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $outcome = $this->recorder()->apply($this->event('whatsapp.message.failed', [
            'type' => 'whatsapp.message.failed',
            'message' => [
                'id' => self::WAMID,
                'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
            ],
        ]));

        $this->assertSame('fallado, fallback disparado', $outcome);

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertStringContainsString('131047', $recipient->failure_reason);
        $this->assertStringContainsString('Re-engagement', $recipient->failure_reason);

        $fallback = AlertRecipient::where('alert_id', $recipient->alert_id)
            ->where('channel', Channel::Email)
            ->firstOrFail();

        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);
    }

    public function test_a_second_failure_event_does_not_escalate_twice(): void
    {
        Queue::fake();

        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $this->recorder()->apply($this->event('whatsapp.message.failed'));
        $outcome = $this->recorder()->apply($this->event('whatsapp.message.failed'));

        $this->assertSame('ignorado: ya fallado', $outcome);
        $this->assertSame(1, AlertRecipient::where('alert_id', $recipient->alert_id)
            ->where('channel', Channel::Email)->count());
        Queue::assertPushed(DeliverAlertJob::class, 1);
    }

    /** A fallback was already dispatched; resurrecting the recipient would misreport it. */
    public function test_progress_events_do_not_resurrect_a_failed_recipient(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Failed);

        $outcome = $this->recorder()->apply($this->event('whatsapp.message.delivered'));

        $this->assertSame('ignorado: recipient ya fallado', $outcome);
        $this->assertSame(DeliveryStatus::Failed, $recipient->refresh()->status);
    }

    public function test_falls_back_to_a_generic_reason_when_the_payload_carries_no_error_detail(): void
    {
        Queue::fake();
        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $this->recorder()->apply($this->event('whatsapp.message.failed'));

        $this->assertSame('reportado como fallido por el proveedor', $recipient->refresh()->failure_reason);
    }

    /** Inbound messages and traffic from other systems share the webhook endpoint. */
    public function test_an_unknown_message_id_is_reported_without_failing(): void
    {
        $this->createRecipient($this->createManagerProfile(), $this->createAlert());

        $event = WhatsappWebhookEvent::create([
            'provider' => 'kapso',
            'idempotency_key' => Str::uuid()->toString(),
            'event_type' => 'whatsapp.message.delivered',
            'provider_message_id' => 'wamid.DESCONOCIDO',
            'payload' => ['message' => ['id' => 'wamid.DESCONOCIDO']],
        ]);

        $this->assertSame('sin recipient para ese message id', $this->recorder()->apply($event));
    }

    public function test_an_unhandled_event_type_is_definitive(): void
    {
        $this->expectException(UnsupportedWebhookEventException::class);
        $this->expectExceptionMessageMatches('/no manejado/');

        $this->recorder()->apply($this->event('whatsapp.conversation.created'));
    }

    /** The shape a buffered batch would take: no single resolvable message id. */
    public function test_a_payload_without_a_message_id_is_definitive(): void
    {
        $event = WhatsappWebhookEvent::create([
            'provider' => 'kapso',
            'idempotency_key' => Str::uuid()->toString(),
            'event_type' => 'whatsapp.message.delivered',
            'provider_message_id' => null,
            'payload' => ['events' => [['type' => 'whatsapp.message.delivered']]],
        ]);

        $this->expectException(UnsupportedWebhookEventException::class);
        $this->expectExceptionMessageMatches('/sin message\.id/');

        $this->recorder()->apply($event);
    }

    /**
     * Proves the Twilio translation genuinely reuses RecordDeliveryStatus: the SAME recorder,
     * the SAME PRECEDENCE table, is fed a `twilio.message.*` event type and behaves exactly
     * like its Kapso equivalent, including the monotonic guard (a `sent` arriving after a
     * `delivered` must not regress the recipient).
     */
    public function test_twilio_delivered_advances_a_sent_recipient_like_its_kapso_equivalent(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $outcome = $this->recorder()->apply($this->event('twilio.message.delivered'));

        $this->assertSame('aplicado: delivered', $outcome);
        $this->assertSame(DeliveryStatus::Delivered, $recipient->refresh()->status);
        $this->assertNotNull($recipient->delivered_at);
    }

    public function test_twilio_sent_arriving_after_delivered_does_not_regress_the_recipient(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Delivered);

        $outcome = $this->recorder()->apply($this->event('twilio.message.sent'));

        $this->assertSame('ignorado: sent no supera delivered', $outcome);
        $this->assertSame(DeliveryStatus::Delivered, $recipient->refresh()->status);
    }

    /** Twilio's "queued"/"sending" both mean only "the provider accepted it" — same as Sent. */
    public function test_twilio_queued_and_sending_both_map_to_sent(): void
    {
        $this->recipientWith(DeliveryStatus::Pending);

        $this->assertSame('aplicado: sent', $this->recorder()->apply($this->event('twilio.message.queued')));
    }

    public function test_twilio_sending_maps_to_sent(): void
    {
        $this->recipientWith(DeliveryStatus::Pending);

        $this->assertSame('aplicado: sent', $this->recorder()->apply($this->event('twilio.message.sending')));
    }

    public function test_twilio_read_maps_to_read(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Delivered);

        $this->assertSame('aplicado: read', $this->recorder()->apply($this->event('twilio.message.read')));
        $this->assertSame(DeliveryStatus::Read, $recipient->refresh()->status);
    }

    /** `undelivered` must not be silently dropped: it maps to Failed and escalates. */
    public function test_twilio_undelivered_maps_to_failed_and_escalates_to_email(): void
    {
        Queue::fake();
        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $outcome = $this->recorder()->apply($this->event('twilio.message.undelivered', [
            'message' => ['id' => self::WAMID, 'errors' => [['code' => 30005, 'title' => 'Unknown destination handset']]],
        ]));

        $this->assertSame('fallado, fallback disparado', $outcome);
        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertStringContainsString('30005', $recipient->failure_reason);

        Queue::assertPushed(DeliverAlertJob::class);
    }

    /** Twilio's failed reads the SAME reshaped payload path (`message.errors.0.title`) as Kapso. */
    public function test_twilio_failed_reads_the_reshaped_error_detail_via_failure_reason(): void
    {
        Queue::fake();
        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $this->recorder()->apply($this->event('twilio.message.failed', [
            'message' => ['id' => self::WAMID, 'errors' => [['code' => 63016, 'title' => 'Channel policy violation']]],
        ]));

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertStringContainsString('63016', $recipient->failure_reason);
        $this->assertStringContainsString('Channel policy violation', $recipient->failure_reason);
    }

    /** An unknown/unmapped MessageStatus is closed with an error, not retried forever. */
    public function test_an_unmapped_twilio_status_is_definitive(): void
    {
        $this->expectException(UnsupportedWebhookEventException::class);
        $this->expectExceptionMessageMatches('/no manejado/');

        $this->recorder()->apply($this->event('twilio.message.accepted'));
    }

    /** Falls back to the payload when the denormalized column was not populated. */
    public function test_resolves_the_message_id_from_the_payload_when_the_column_is_null(): void
    {
        $recipient = $this->recipientWith(DeliveryStatus::Sent);

        $event = WhatsappWebhookEvent::create([
            'provider' => 'kapso',
            'idempotency_key' => Str::uuid()->toString(),
            'event_type' => 'whatsapp.message.delivered',
            'provider_message_id' => null,
            'payload' => ['message' => ['id' => self::WAMID]],
        ]);

        $this->assertSame('aplicado: delivered', $this->recorder()->apply($event));
        $this->assertSame(DeliveryStatus::Delivered, $recipient->refresh()->status);
    }
}
