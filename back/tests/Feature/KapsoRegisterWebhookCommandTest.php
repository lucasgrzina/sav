<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KapsoRegisterWebhookCommandTest extends TestCase
{
    private const ENDPOINT = 'https://api.kapso.test/platform/v1/whatsapp/webhooks';
    private const URL = 'https://tunnel.test/api/v1/webhooks/kapso';
    private const SECRET = 'super-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.kapso.base_url' => 'https://api.kapso.test',
            'notifications.kapso.api_key' => 'kapso-key',
            'notifications.kapso.phone_number_id' => 'PN123',
            'notifications.kapso.webhook_secret' => self::SECRET,
        ]);

        Http::preventStrayRequests();
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param array<string, mixed> $saved
     */
    private function fakeApi(array $existing = [], array $saved = [], int $totalPages = 1): void
    {
        Http::fake(function (Request $request) use ($existing, $saved, $totalPages) {
            if ($request->method() === 'GET') {
                $page = (int) ($request->data()['page'] ?? 1);

                return Http::response([
                    'data' => $page === 1 ? $existing : [],
                    'meta' => ['page' => $page, 'per_page' => 100, 'total_pages' => $totalPages, 'total_count' => count($existing)],
                ]);
            }

            return Http::response(['data' => $saved + [
                'id' => 'wh-created',
                'url' => self::URL,
                'events' => ['whatsapp.message.sent'],
                'payload_version' => 'v2',
                'secret_key' => self::SECRET,
            ]]);
        });
    }

    public function test_fails_without_a_configured_secret(): void
    {
        config(['notifications.kapso.webhook_secret' => null]);

        $this->artisan('kapso:register-webhook', ['url' => self::URL])
            ->expectsOutputToContain('Falta KAPSO_WEBHOOK_SECRET')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_rejects_a_non_https_url(): void
    {
        $this->artisan('kapso:register-webhook', ['url' => 'http://localhost:8001/api/v1/webhooks/kapso'])
            ->expectsOutputToContain('debe ser HTTPS')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_shows_the_post_and_never_leaks_the_secret(): void
    {
        Http::fake();

        $this->artisan('kapso:register-webhook', ['url' => self::URL, '--dry-run' => true])
            ->expectsOutputToContain('POST ' . self::ENDPOINT)
            ->expectsOutputToContain('<KAPSO_WEBHOOK_SECRET>')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_creates_a_webhook_with_the_delivery_status_events(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:register-webhook', ['url' => self::URL])
            ->expectsOutputToContain('Webhook registrado.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $body = $request->data()['whatsapp_webhook'];

            return $request->method() === 'POST'
                && $request->url() === self::ENDPOINT
                && $request->hasHeader('X-API-Key', 'kapso-key')
                && $body['url'] === self::URL
                && $body['phone_number_id'] === 'PN123'
                && $body['kind'] === 'kapso'
                && $body['secret_key'] === self::SECRET
                && $body['buffer_enabled'] === false
                && $body['events'] === [
                    'whatsapp.message.received',
                    'whatsapp.message.sent',
                    'whatsapp.message.delivered',
                    'whatsapp.message.read',
                    'whatsapp.message.failed',
                ];
        });
    }

    public function test_update_repoints_the_existing_webhook_of_this_phone_number(): void
    {
        $this->fakeApi(existing: [
            ['id' => 'wh-otro', 'url' => 'https://viejo.test/hook', 'phone_number_id' => 'OTRO'],
            ['id' => 'wh-mio', 'url' => 'https://viejo.test/hook', 'phone_number_id' => 'PN123'],
        ]);

        $this->artisan('kapso:register-webhook', ['url' => self::URL, '--update' => true])
            ->expectsOutputToContain('Webhook existente: wh-mio')
            ->expectsOutputToContain('Webhook actualizado.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $body = $request->data()['whatsapp_webhook'];

            // PATCH targets a webhook already bound to its number, so sending
            // phone_number_id again would be meaningless at best.
            return $request->url() === self::ENDPOINT . '/wh-mio'
                && $body['url'] === self::URL
                && ! array_key_exists('phone_number_id', $body);
        });
    }

    public function test_update_falls_back_to_creating_when_there_is_nothing_to_update(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:register-webhook', ['url' => self::URL, '--update' => true])
            ->expectsOutputToContain('No hay webhook previo')
            ->expectsOutputToContain('Webhook registrado.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_update_refuses_to_guess_when_the_number_has_several_webhooks(): void
    {
        $this->fakeApi(existing: [
            ['id' => 'wh-a', 'url' => 'https://a.test/hook', 'phone_number_id' => 'PN123'],
            ['id' => 'wh-b', 'url' => 'https://b.test/hook', 'phone_number_id' => 'PN123'],
        ]);

        $this->artisan('kapso:register-webhook', ['url' => self::URL, '--update' => true])
            ->expectsOutputToContain('más de un webhook')
            ->expectsOutputToContain('wh-a')
            ->expectsOutputToContain('wh-b')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PATCH'], true));
    }

    public function test_an_explicit_id_patches_without_listing(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:register-webhook', ['url' => self::URL, '--id' => 'wh-explicito'])
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'GET');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request->url() === self::ENDPOINT . '/wh-explicito');
    }

    /** A silent mismatch would surface later as a permanent 401 on every delivery. */
    public function test_warns_when_kapso_returns_a_different_secret(): void
    {
        $this->fakeApi(saved: ['secret_key' => 'otro-secreto-distinto']);

        $this->artisan('kapso:register-webhook', ['url' => self::URL])
            ->expectsOutputToContain('NO coincide con KAPSO_WEBHOOK_SECRET')
            ->assertSuccessful();
    }

    public function test_reports_a_rejection_from_kapso(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'url ya registrada']], 422)]);

        $this->artisan('kapso:register-webhook', ['url' => self::URL])
            ->expectsOutputToContain('url ya registrada')
            ->assertFailed();
    }

    public function test_walks_every_page_when_looking_for_the_existing_webhook(): void
    {
        $this->fakeApi(existing: [], totalPages: 3);

        $this->artisan('kapso:register-webhook', ['url' => self::URL, '--update' => true])
            ->assertSuccessful();

        Http::assertSentCount(4); // 3 páginas de listado + el POST
    }
}
