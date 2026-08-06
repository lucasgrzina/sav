<?php

namespace App\Notifications\Http\Controllers;

use App\Notifications\Jobs\ProcessWhatsappWebhookEventJob;
use App\Notifications\Models\WhatsappWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Acknowledges as fast as possible: Kapso requires a 200 within 10 seconds and retries at
 * 10s / 40s / 90s otherwise, so this only verifies-and-stores and hands the interpretation
 * to a queued job. Anything heavier here risks duplicate events under load.
 */
final class KapsoWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = WhatsappWebhookEvent::query()->make([
            'provider' => 'kapso',
            'idempotency_key' => $this->idempotencyKey($request),
            'event_type' => $this->str($request->header('X-Webhook-Event') ?? $request->input('type')) ?? 'unknown',
            'provider_message_id' => $this->str($request->input('message.id')),
            'payload' => $request->all(),
            'payload_version' => $this->str($request->header('X-Webhook-Payload-Version')),
        ]);

        try {
            $event->save();
        } catch (UniqueConstraintViolationException) {
            // Already stored: a provider retry, not a new event. 200 stops the retry chain.
            return response()->json(['status' => 'duplicate']);
        }

        ProcessWhatsappWebhookEventJob::dispatch($event->id);

        return response()->json(['status' => 'accepted']);
    }

    /**
     * X-Idempotency-Key is documented but not guaranteed on every event, and losing an event
     * is worse than deduplicating one: hashing the raw body is a stable stand-in, and
     * re-applying the same status is a no-op anyway thanks to the monotonic guard.
     */
    private function idempotencyKey(Request $request): string
    {
        $header = $this->str($request->header('X-Idempotency-Key'));

        return $header ?? 'sha256:' . hash('sha256', $request->getContent());
    }

    /** Normalizes untrusted input to a column-safe string, or null when absent. */
    private function str(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 250, '');
    }
}
