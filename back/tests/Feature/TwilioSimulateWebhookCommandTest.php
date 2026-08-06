<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwilioSimulateWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'simulate-auth-token';
    private const SID = 'SM1234567890abcdef1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.twilio.token' => self::TOKEN]);
        Http::preventStrayRequests();
    }

    public function test_fails_without_a_configured_token(): void
    {
        config(['notifications.twilio.token' => '']);
        Http::fake();

        $this->artisan('twilio:simulate-webhook', ['sid' => self::SID])
            ->expectsOutputToContain('Falta TWILIO_AUTH_TOKEN')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_rejects_an_unknown_status(): void
    {
        Http::fake();

        $this->artisan('twilio:simulate-webhook', ['sid' => self::SID, '--status' => 'exploded'])
            ->expectsOutputToContain('Status inválido')
            ->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * The invariant this tool exists to protect: the signature has to be computed with the
     * SDK's own algorithm over the exact URL and params sent, so it passes the real
     * middleware unmodified.
     */
    public function test_signs_the_request_so_it_passes_the_real_middleware(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->artisan('twilio:simulate-webhook', ['sid' => self::SID, '--status' => 'delivered'])
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-Twilio-Signature')
                && $request->data()['MessageSid'] === self::SID
                && $request->data()['MessageStatus'] === 'delivered';
        });
    }

    public function test_a_failed_status_carries_the_error_detail(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->artisan('twilio:simulate-webhook', [
            'sid' => self::SID,
            '--status' => 'failed',
            '--error-code' => '63016',
            '--error-message' => 'Channel policy violation',
        ])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->data()['ErrorCode'] === '63016'
            && $request->data()['ErrorMessage'] === 'Channel policy violation');
    }

    public function test_progress_statuses_carry_no_error_params(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->artisan('twilio:simulate-webhook', ['sid' => self::SID, '--status' => 'sent'])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => ! array_key_exists('ErrorCode', $request->data()));
    }

    public function test_reports_a_401_as_a_signature_mismatch_rather_than_a_generic_failure(): void
    {
        Http::fake(['*' => Http::response('Firma de webhook de Twilio inválida.', 401)]);

        $this->artisan('twilio:simulate-webhook', ['sid' => self::SID])
            ->expectsOutputToContain('el endpoint calculó otra firma')
            ->assertFailed();
    }

    public function test_says_so_when_no_recipient_holds_that_message_sid(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->artisan('twilio:simulate-webhook', ['sid' => 'SM_INEXISTENTE'])
            ->expectsOutputToContain('Ningún alert_recipient')
            ->assertSuccessful();
    }
}
