<?php

namespace Tests\Feature;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\WhatsappWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesNotificationFixtures;
use Tests\TestCase;

class KapsoWebhookTest extends TestCase
{
    use CreatesNotificationFixtures, RefreshDatabase;

    private const URL = '/api/v1/webhooks/kapso';
    private const SECRET = 'test-webhook-secret';
    private const WAMID = 'wamid.HBgNMTU1NTE0OTU5Nzg1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.kapso.webhook_secret' => self::SECRET]);
    }

    /**
     * Posts a raw body and signs that exact string. Building the request this way (rather
     * than postJson) is the point: the signature must be verified against the bytes on the
     * wire, so the test must control them.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function send(array $payload, array $headers = [], ?string $signWith = self::SECRET): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($signWith !== null) {
            $server['HTTP_X_WEBHOOK_SIGNATURE'] = hash_hmac('sha256', $body, $signWith);
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }

    /** @return array<string, mixed> */
    private function statusPayload(string $type, string $wamid = self::WAMID): array
    {
        return [
            'type' => $type,
            'message' => ['id' => $wamid, 'to' => '5491122334455'],
        ];
    }

    public function test_rejects_a_request_without_a_signature(): void
    {
        $this->send($this->statusPayload('whatsapp.message.delivered'), signWith: null)
            ->assertStatus(401);

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_rejects_a_request_signed_with_the_wrong_secret(): void
    {
        $this->send($this->statusPayload('whatsapp.message.delivered'), signWith: 'otro-secreto')
            ->assertStatus(401);

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    /** Failing open would mean accepting unverified webhooks. */
    public function test_refuses_to_process_when_no_secret_is_configured(): void
    {
        config(['notifications.kapso.webhook_secret' => null]);

        $this->send($this->statusPayload('whatsapp.message.delivered'))->assertStatus(500);

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_accepts_a_valid_signature_and_stores_the_raw_event(): void
    {
        $response = $this->send(
            $this->statusPayload('whatsapp.message.delivered'),
            ['X-Webhook-Event' => 'whatsapp.message.delivered', 'X-Idempotency-Key' => 'evt-1', 'X-Webhook-Payload-Version' => 'v2'],
        );

        $response->assertOk()->assertJson(['status' => 'accepted']);

        $event = WhatsappWebhookEvent::sole();
        $this->assertSame('kapso', $event->provider);
        $this->assertSame('whatsapp.message.delivered', $event->event_type);
        $this->assertSame('evt-1', $event->idempotency_key);
        $this->assertSame(self::WAMID, $event->provider_message_id);
        $this->assertSame('v2', $event->payload_version);
        $this->assertNotNull($event->processed_at);
    }

    /** The provider retries on any non-200; the unique idempotency key absorbs those. */
    public function test_a_retried_event_is_acknowledged_without_being_stored_twice(): void
    {
        $payload = $this->statusPayload('whatsapp.message.delivered');
        $headers = ['X-Webhook-Event' => 'whatsapp.message.delivered', 'X-Idempotency-Key' => 'evt-dup'];

        $this->send($payload, $headers)->assertOk()->assertJson(['status' => 'accepted']);
        $this->send($payload, $headers)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, WhatsappWebhookEvent::count());
    }

    /** Without X-Idempotency-Key the body hash still deduplicates. */
    public function test_deduplicates_by_body_hash_when_the_idempotency_header_is_absent(): void
    {
        $payload = $this->statusPayload('whatsapp.message.delivered');
        $headers = ['X-Webhook-Event' => 'whatsapp.message.delivered'];

        $this->send($payload, $headers)->assertOk();
        $this->send($payload, $headers)->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, WhatsappWebhookEvent::count());
        $this->assertStringStartsWith('sha256:', WhatsappWebhookEvent::sole()->idempotency_key);
    }

    /**
     * Regression guard for the classic failure of this integration: signing a re-serialized
     * body instead of the raw one. Accents and quotes are where the two diverge.
     */
    public function test_verifies_a_body_containing_unicode_and_escaped_quotes(): void
    {
        $payload = [
            'type' => 'whatsapp.message.delivered',
            'message' => ['id' => self::WAMID],
            'context' => ['body' => 'Se creó el programa "Sincronización IATF" — 100% ok / listo'],
        ];

        $this->send($payload, ['X-Webhook-Event' => 'whatsapp.message.delivered'])->assertOk();

        $this->assertSame(1, WhatsappWebhookEvent::count());
    }

    public function test_a_delivered_event_updates_the_recipient_end_to_end(): void
    {
        $recipient = $this->createRecipient(
            $this->createManagerProfile(),
            $this->createAlert(),
            Channel::Whatsapp,
            DeliveryStatus::Sent,
            self::WAMID,
        );

        $this->send(
            $this->statusPayload('whatsapp.message.delivered'),
            ['X-Webhook-Event' => 'whatsapp.message.delivered'],
        )->assertOk();

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
        $this->assertSame('aplicado: delivered', WhatsappWebhookEvent::sole()->outcome);
    }

    public function test_a_failed_event_escalates_to_the_email_fallback_end_to_end(): void
    {
        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->createRecipient(
            $profile,
            $alert,
            Channel::Whatsapp,
            DeliveryStatus::Sent,
            self::WAMID,
        );

        $this->send([
            'type' => 'whatsapp.message.failed',
            'message' => ['id' => self::WAMID, 'errors' => [['code' => 131047, 'title' => 'Re-engagement message']]],
        ], ['X-Webhook-Event' => 'whatsapp.message.failed'])->assertOk();

        $this->assertSame(DeliveryStatus::Failed, $recipient->refresh()->status);

        $fallback = AlertRecipient::where('alert_id', $alert->id)
            ->where('channel', Channel::Email)
            ->firstOrFail();
        $this->assertSame($profile->id, $fallback->user_profile_id);
    }

    /** An unhandled type is closed with an explanation instead of being retried forever. */
    public function test_an_unhandled_event_type_is_stored_and_closed_with_an_error(): void
    {
        $this->send(
            ['type' => 'whatsapp.conversation.created', 'message' => ['id' => self::WAMID]],
            ['X-Webhook-Event' => 'whatsapp.conversation.created'],
        )->assertOk();

        $event = WhatsappWebhookEvent::sole();
        $this->assertNotNull($event->processed_at);
        $this->assertStringContainsString('no manejado', $event->error);
        $this->assertNull($event->outcome);
    }

    public function test_the_endpoint_requires_no_authenticated_user(): void
    {
        $this->send($this->statusPayload('whatsapp.message.delivered'))->assertOk();

        $this->assertGuest();
    }

    /** An inbound opt-out message is routed to RecordInboundOptOut, not RecordDeliveryStatus. */
    public function test_an_inbound_opt_out_message_writes_an_opt_out_row_end_to_end(): void
    {
        $payload = [
            'type' => 'whatsapp.message.received',
            'message' => [
                'id' => 'wamid.INBOUND',
                'from' => '5491134290838',
                'text' => ['body' => 'BAJA'],
            ],
        ];

        $this->send($payload, ['X-Webhook-Event' => 'whatsapp.message.received'])->assertOk();

        $this->assertDatabaseHas('opt_outs', ['phone' => '5491134290838', 'channel' => 'whatsapp']);

        $event = WhatsappWebhookEvent::sole();
        $this->assertSame('opt-out registrado', $event->outcome);
        $this->assertNotNull($event->processed_at);
    }
}
