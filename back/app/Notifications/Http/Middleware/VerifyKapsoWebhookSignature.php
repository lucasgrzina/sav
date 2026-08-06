<?php

namespace App\Notifications\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signature IS the authentication for this endpoint — there is no platform user behind
 * a provider callback, which is why the route carries no auth:sanctum.
 */
final class VerifyKapsoWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('notifications.kapso.webhook_secret');

        if ($secret === '') {
            // Failing open would mean accepting unverified webhooks. A missing secret is a
            // deployment error, and it must read as one.
            abort(500, 'KAPSO_WEBHOOK_SECRET no configurado.');
        }

        $provided = (string) $request->header('X-Webhook-Signature', '');

        // HMAC-SHA256, hex, no "sha256=" prefix, over the RAW body. Signing a re-serialized
        // body (json_encode($request->all())) changes key order plus unicode and slash
        // escaping, and the signature then never matches — for any payload.
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Firma de webhook inválida.');
        }

        return $next($request);
    }
}
