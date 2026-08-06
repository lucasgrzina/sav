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
use Twilio\Security\RequestValidator;

class TwilioWebhookTest extends TestCase
{
    use CreatesNotificationFixtures, RefreshDatabase;

    private const TOKEN = 'test-auth-token';
    private const SID = 'SM1234567890abcdef1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.twilio.token' => self::TOKEN]);
    }

    /**
     * Posts real form params (application/x-www-form-urlencoded shape) and signs them the
     * way Twilio does: HMAC-SHA1 over the exact URL plus sorted params, base64-encoded.
     *
     * @param array<string, string> $params
     */
    private function send(array $params, ?string $signWith = self::TOKEN): TestResponse
    {
        $url = route('webhooks.twilio');

        $server = ['HTTP_ACCEPT' => 'application/json'];

        if ($signWith !== null) {
            $signature = (new RequestValidator($signWith))->computeSignature($url, $params);
            $server['HTTP_X_TWILIO_SIGNATURE'] = $signature;
        }

        return $this->call('POST', $url, $params, [], [], $server);
    }

    /** @return array<string, string> */
    private function statusParams(string $status, string $sid = self::SID): array
    {
        return ['MessageSid' => $sid, 'MessageStatus' => $status, 'To' => 'whatsapp:+5491122334455', 'From' => 'whatsapp:+14155238886'];
    }

    public function test_rejects_a_request_without_a_signature(): void
    {
        $this->send($this->statusParams('delivered'), signWith: null)->assertStatus(401);

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_rejects_a_request_signed_with_the_wrong_token(): void
    {
        $this->send($this->statusParams('delivered'), signWith: 'otro-token')->assertStatus(401);

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    /** Failing open would mean accepting unverified webhooks. */
    public function test_refuses_to_process_when_no_token_is_configured(): void
    {
        config(['notifications.twilio.token' => null]);

        $this->send($this->statusParams('delivered'))->assertStatus(500);

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_accepts_a_valid_signature_and_stores_the_reshaped_event(): void
    {
        $this->send($this->statusParams('delivered'))->assertNoContent();

        $event = WhatsappWebhookEvent::sole();
        $this->assertSame('twilio', $event->provider);
        $this->assertSame('twilio.message.delivered', $event->event_type);
        $this->assertSame('twilio:' . self::SID . ':delivered', $event->idempotency_key);
        $this->assertSame(self::SID, $event->provider_message_id);
        $this->assertSame(self::SID, $event->payload['message']['id']);
        $this->assertNotNull($event->processed_at);
    }

    /** Twilio sends no idempotency header: MessageSid+MessageStatus is the derived key. */
    public function test_the_same_callback_delivered_twice_produces_one_event_and_one_transition(): void
    {
        $this->createRecipient(
            $this->createManagerProfile(),
            $this->createAlert(),
            Channel::Whatsapp,
            DeliveryStatus::Sent,
            self::SID,
        );

        $this->send($this->statusParams('delivered'))->assertNoContent();
        $this->send($this->statusParams('delivered'))->assertNoContent();

        $this->assertSame(1, WhatsappWebhookEvent::count());
        $this->assertSame(1, AlertRecipient::where('provider_message_id', self::SID)
            ->where('status', DeliveryStatus::Delivered)->count());
    }

    public function test_a_delivered_event_updates_the_recipient_end_to_end(): void
    {
        $recipient = $this->createRecipient(
            $this->createManagerProfile(),
            $this->createAlert(),
            Channel::Whatsapp,
            DeliveryStatus::Sent,
            self::SID,
        );

        $this->send($this->statusParams('delivered'))->assertNoContent();

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
        $this->assertSame('aplicado: delivered', WhatsappWebhookEvent::sole()->outcome);
    }

    public function test_a_failed_event_escalates_to_the_email_fallback_end_to_end(): void
    {
        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->createRecipient($profile, $alert, Channel::Whatsapp, DeliveryStatus::Sent, self::SID);

        $params = $this->statusParams('failed') + ['ErrorCode' => '63016', 'ErrorMessage' => 'Channel policy violation'];
        $this->send($params)->assertNoContent();

        $this->assertSame(DeliveryStatus::Failed, $recipient->refresh()->status);
        $this->assertStringContainsString('63016', $recipient->failure_reason);

        $fallback = AlertRecipient::where('alert_id', $alert->id)
            ->where('channel', Channel::Email)
            ->firstOrFail();
        $this->assertSame($profile->id, $fallback->user_profile_id);
    }

    /** `undelivered` must not be silently dropped: it is a definitive failure, not ignored. */
    public function test_an_undelivered_event_also_escalates_to_the_email_fallback(): void
    {
        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->createRecipient($profile, $alert, Channel::Whatsapp, DeliveryStatus::Sent, self::SID);

        $params = $this->statusParams('undelivered') + ['ErrorCode' => '30005', 'ErrorMessage' => 'Unknown destination handset'];
        $this->send($params)->assertNoContent();

        $this->assertSame(DeliveryStatus::Failed, $recipient->refresh()->status);
        AlertRecipient::where('alert_id', $alert->id)->where('channel', Channel::Email)->firstOrFail();
    }

    public function test_a_full_progression_respects_precedence(): void
    {
        $recipient = $this->createRecipient(
            $this->createManagerProfile(),
            $this->createAlert(),
            Channel::Whatsapp,
            DeliveryStatus::Pending,
            self::SID,
        );

        $this->send($this->statusParams('queued'))->assertNoContent();
        $this->send($this->statusParams('sent'))->assertNoContent();
        $this->send($this->statusParams('delivered'))->assertNoContent();
        $this->send($this->statusParams('read'))->assertNoContent();

        $this->assertSame(DeliveryStatus::Read, $recipient->refresh()->status);
    }

    /** An unmapped MessageStatus is stored and closed with an error, never retried forever. */
    public function test_an_unmapped_status_is_stored_and_closed_with_an_error(): void
    {
        $this->send($this->statusParams('accepted'))->assertNoContent();

        $event = WhatsappWebhookEvent::sole();
        $this->assertNotNull($event->processed_at);
        $this->assertStringContainsString('no manejado', $event->error);
    }

    /** A payload missing MessageSid/MessageStatus is acknowledged: nothing actionable, no retry. */
    public function test_a_payload_without_message_sid_or_status_is_acknowledged_and_stores_nothing(): void
    {
        $url = route('webhooks.twilio');
        $params = ['To' => 'whatsapp:+5491122334455'];
        $signature = (new RequestValidator(self::TOKEN))->computeSignature($url, $params);

        $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $signature])
            ->assertNoContent();

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_the_endpoint_requires_no_authenticated_user(): void
    {
        $this->send($this->statusParams('delivered'))->assertNoContent();

        $this->assertGuest();
    }
}
