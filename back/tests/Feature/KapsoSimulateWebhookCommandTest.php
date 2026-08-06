<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KapsoSimulateWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'simulate-secret';
    private const WAMID = 'wamid.HBgNMTU1NTE0OTU5Nzg1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.kapso.webhook_secret' => self::SECRET]);
        Http::preventStrayRequests();
    }

    public function test_fails_without_a_configured_secret(): void
    {
        config(['notifications.kapso.webhook_secret' => '']);
        Http::fake();

        $this->artisan('kapso:simulate-webhook', ['wamid' => self::WAMID])
            ->expectsOutputToContain('Falta KAPSO_WEBHOOK_SECRET')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_rejects_an_unknown_event(): void
    {
        Http::fake();

        $this->artisan('kapso:simulate-webhook', ['wamid' => self::WAMID, '--event' => 'exploded'])
            ->expectsOutputToContain('Evento inválido')
            ->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * The invariant this tool exists to protect: the signature has to be the HMAC of the
     * exact bytes that go out, not of a re-encoded copy of them.
     */
    public function test_signs_the_exact_body_it_sends(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', ['wamid' => self::WAMID])->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $signature = $request->header('X-Webhook-Signature')[0] ?? '';

            return hash_equals(hash_hmac('sha256', $request->body(), self::SECRET), $signature);
        });
    }

    public function test_maps_the_event_option_to_the_kapso_event_type(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', ['wamid' => self::WAMID, '--event' => 'read'])
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->header('X-Webhook-Event')[0] === 'whatsapp.message.read'
                && $request->data()['type'] === 'whatsapp.message.read'
                && $request->data()['message']['id'] === self::WAMID;
        });
    }

    public function test_a_failed_event_carries_the_meta_error_detail(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', [
            'wamid' => self::WAMID,
            '--event' => 'failed',
            '--code' => '131026',
            '--title' => 'Message undeliverable',
        ])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->data()['message']['errors'] === [[
            'code' => 131026,
            'title' => 'Message undeliverable',
        ]]);
    }

    public function test_progress_events_carry_no_error_block(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', ['wamid' => self::WAMID, '--event' => 'sent'])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => ! array_key_exists('errors', $request->data()['message']));
    }

    public function test_reports_a_401_as_a_secret_mismatch_rather_than_a_generic_failure(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Firma de webhook inválida.'], 401)]);

        $this->artisan('kapso:simulate-webhook', ['wamid' => self::WAMID])
            ->expectsOutputToContain('el endpoint calculó otra firma')
            ->assertFailed();
    }

    public function test_reuses_a_given_idempotency_key_so_deduplication_can_be_tested(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', [
            'wamid' => self::WAMID,
            '--idempotency-key' => 'evt-fijo',
        ])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->header('X-Idempotency-Key')[0] === 'evt-fijo');
    }

    public function test_says_so_when_no_recipient_holds_that_message_id(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', ['wamid' => 'wamid.INEXISTENTE'])
            ->expectsOutputToContain('Ningún alert_recipient')
            ->assertSuccessful();
    }

    public function test_an_event_received_sends_the_from_and_text_body_shape(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', [
            'wamid' => 'wamid.INBOUND',
            '--event' => 'received',
            '--from' => '5491134290838',
            '--text' => 'BAJA',
        ])->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->header('X-Webhook-Event')[0] === 'whatsapp.message.received'
                && $request->data()['message']['from'] === '5491134290838'
                && $request->data()['message']['text']['body'] === 'BAJA';
        });
    }

    /**
     * Http::fake() intercepts the request before it reaches the local webhook endpoint, so
     * no opt-out is actually written here — the same reason the existing recipient-lookup
     * test above reports "no recipient". What matters is that --event=received reports the
     * opt_outs effect, not alert_recipients (which would be meaningless for an inbound
     * message).
     */
    public function test_an_event_received_reports_the_opt_out_effect_instead_of_a_recipient(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'])]);

        $this->artisan('kapso:simulate-webhook', [
            'wamid' => 'wamid.INBOUND',
            '--event' => 'received',
            '--from' => '5491134290838',
            '--text' => 'BAJA',
        ])
            ->expectsOutputToContain('opt_outs:')
            ->expectsOutputToContain('sin baja registrada')
            ->doesntExpectOutputToContain('alert_recipient')
            ->assertSuccessful();
    }
}
