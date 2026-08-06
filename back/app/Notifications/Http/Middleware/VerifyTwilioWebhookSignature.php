<?php

namespace App\Notifications\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

/**
 * The signature IS the authentication for this endpoint — there is no platform user behind
 * a provider callback, which is why the route carries no auth:sanctum. Independent from
 * VerifyKapsoWebhookSignature: Twilio's scheme (HMAC-SHA1 over the full URL plus sorted POST
 * params, base64, `X-Twilio-Signature`) shares nothing reusable with Kapso's (HMAC-SHA256 over
 * the raw body, hex, `X-Webhook-Signature`) beyond a final `hash_equals` comparison.
 */
final class VerifyTwilioWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('notifications.twilio.token');

        if ($token === '') {
            // Failing open would mean accepting unverified webhooks. A missing token is a
            // deployment error, and it must read as one.
            abort(500, 'TWILIO_AUTH_TOKEN no configurado.');
        }

        $provided = (string) $request->header('X-Twilio-Signature', '');

        $validator = new RequestValidator($token);

        if ($provided === '' || ! $validator->validate($provided, $this->signedUrl($request), $request->request->all())) {
            abort(401, 'Firma de webhook de Twilio inválida.');
        }

        return $next($request);
    }

    /**
     * Twilio signs the full external URL it calls (NOT the raw body — a completely different
     * scheme from Kapso's) plus every POST param, sorted by key. So this must reproduce that
     * URL byte for byte.
     *
     * `$request->fullUrl()` is correct only when the app sees the same scheme and host Twilio
     * called. `trustProxies` in bootstrap/app.php covers the usual reverse-proxy case, but a
     * quick tunnel that forwards https to a plain-http origin can still produce a mismatch that
     * surfaces as a 401 and reads exactly like a wrong auth token. When
     * TWILIO_STATUS_CALLBACK_URL is set we trust it instead: it is the same value handed to
     * Twilio as the statusCallback, so it is by definition the URL that was signed.
     */
    private function signedUrl(Request $request): string
    {
        $configured = trim((string) config('notifications.twilio.status_callback_url'));

        if ($configured === '') {
            return $request->fullUrl();
        }

        $query = $request->getQueryString();

        return $query === null ? $configured : $configured . '?' . $query;
    }
}
