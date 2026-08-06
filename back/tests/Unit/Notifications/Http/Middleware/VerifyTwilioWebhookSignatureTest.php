<?php

namespace Tests\Unit\Notifications\Http\Middleware;

use App\Notifications\Http\Middleware\VerifyTwilioWebhookSignature;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * This is the authentication boundary for an unauthenticated public endpoint: the signature
 * IS the auth, so it needs real coverage, not a smoke test. Exercises the middleware
 * directly (no HTTP round trip) so the exact $request->fullUrl() the middleware validates
 * against is under the test's control.
 */
class VerifyTwilioWebhookSignatureTest extends TestCase
{
    private const TOKEN = 'test-auth-token';
    private const URL = 'https://sav.test/api/v1/webhooks/twilio';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.twilio.token' => self::TOKEN,
            // Empty by default so the tests below exercise the $request->fullUrl() path; the
            // configured-URL path has its own tests at the bottom of this file.
            'notifications.twilio.status_callback_url' => '',
        ]);
    }

    /** @param array<string, string> $params */
    private function requestFor(string $url, array $params, ?string $signature): Request
    {
        $request = Request::create($url, 'POST', $params);

        if ($signature !== null) {
            $request->headers->set('X-Twilio-Signature', $signature);
        }

        return $request;
    }

    /** @param array<string, string> $params */
    private function sign(string $url, array $params): string
    {
        return (new RequestValidator(self::TOKEN))->computeSignature($url, $params);
    }

    public function test_accepts_a_correctly_signed_request(): void
    {
        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $request = $this->requestFor(self::URL, $params, $this->sign(self::URL, $params));

        $response = (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_rejects_a_request_without_a_signature_header(): void
    {
        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $request = $this->requestFor(self::URL, $params, null);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Firma de webhook de Twilio inválida.');

        (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));
    }

    public function test_rejects_a_request_signed_with_the_wrong_token(): void
    {
        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $wrongSignature = (new RequestValidator('otro-token'))->computeSignature(self::URL, $params);
        $request = $this->requestFor(self::URL, $params, $wrongSignature);

        try {
            (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));
            $this->fail('Se esperaba un 401.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    /**
     * Regression guard for the risk the plan flags explicitly: Twilio signs the exact URL
     * it calls. A signature computed against a different URL (here, a trailing slash) must
     * not validate — this is exactly what would happen behind a tunnel/proxy mismatch.
     */
    public function test_rejects_a_signature_computed_against_a_different_url(): void
    {
        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $signature = $this->sign(self::URL . '/', $params);
        $request = $this->requestFor(self::URL, $params, $signature);

        try {
            (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));
            $this->fail('Se esperaba un 401.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    /**
     * The reason TWILIO_STATUS_CALLBACK_URL overrides fullUrl(): a tunnel that terminates TLS
     * and forwards plain http to the local origin makes Laravel see `http://localhost/...` while
     * Twilio signed `https://sav.test/...`. Without the override that is a permanent 401 that
     * reads exactly like a wrong auth token.
     */
    public function test_validates_against_the_configured_url_when_the_request_url_differs(): void
    {
        config(['notifications.twilio.status_callback_url' => self::URL]);

        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $request = $this->requestFor(
            'http://localhost/api/v1/webhooks/twilio',
            $params,
            $this->sign(self::URL, $params),
        );

        $response = (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    /** The configured URL must WIN, not merely be accepted as an alternative. */
    public function test_ignores_the_request_url_once_a_callback_url_is_configured(): void
    {
        config(['notifications.twilio.status_callback_url' => self::URL]);

        $arrivalUrl = 'http://localhost/api/v1/webhooks/twilio';
        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $request = $this->requestFor($arrivalUrl, $params, $this->sign($arrivalUrl, $params));

        try {
            (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));
            $this->fail('Se esperaba un 401: la firma se validó contra la URL de llegada.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    /** Twilio signs the query string too, and it comes from the request, not from config. */
    public function test_appends_the_request_query_string_to_the_configured_url(): void
    {
        config(['notifications.twilio.status_callback_url' => self::URL]);

        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $request = $this->requestFor(
            'http://localhost/api/v1/webhooks/twilio?recipient=42',
            $params,
            $this->sign(self::URL . '?recipient=42', $params),
        );

        $response = (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    /** Failing open would mean accepting unverified webhooks. */
    public function test_fails_closed_when_no_auth_token_is_configured(): void
    {
        config(['notifications.twilio.token' => '']);

        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $request = $this->requestFor(self::URL, $params, $this->sign(self::URL, $params));

        try {
            (new VerifyTwilioWebhookSignature())->handle($request, fn () => response('ok'));
            $this->fail('Se esperaba un 500.');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
    }
}
