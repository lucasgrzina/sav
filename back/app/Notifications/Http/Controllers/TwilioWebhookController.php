<?php

namespace App\Notifications\Http\Controllers;

use App\Notifications\Jobs\ProcessWhatsappWebhookEventJob;
use App\Notifications\Models\WhatsappWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twilio Status Callback endpoint. Always answers 2xx unless the signature is invalid: a
 * 4xx/5xx here triggers Twilio's retry chain and never fixes anything on our side.
 */
final class TwilioWebhookController
{
    public function __invoke(Request $request): Response
    {
        $sid = trim((string) $request->input('MessageSid', ''));
        $status = trim((string) $request->input('MessageStatus', ''));

        if ($sid === '' || $status === '') {
            // Not a status callback shape we recognize. Acknowledge anyway: Twilio does not
            // retry on 2xx, and there is nothing actionable here.
            return response()->noContent();
        }

        $event = WhatsappWebhookEvent::query()->make([
            'provider' => 'twilio',
            'idempotency_key' => "twilio:{$sid}:{$status}",
            'event_type' => "twilio.message.{$status}",
            'provider_message_id' => $sid,
            'payload' => $this->payload($request, $sid, $status),
        ]);

        try {
            $event->save();
        } catch (UniqueConstraintViolationException) {
            return response()->noContent(); // Retried by Twilio, not a new event.
        }

        ProcessWhatsappWebhookEventJob::dispatch($event->id);

        return response()->noContent();
    }

    /**
     * Reshapes Twilio's flat form params into the SAME nested shape Kapso's payload already
     * has (`message.id`, `message.errors.0.{code,title}`), so RecordDeliveryStatus reads it
     * without knowing which provider sent it.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $sid, string $status): array
    {
        $errorCode = $request->input('ErrorCode');
        $errorMessage = $request->input('ErrorMessage');

        $message = ['id' => $sid];

        if ($errorCode !== null || $errorMessage !== null) {
            $message['errors'] = [['code' => $errorCode, 'title' => $errorMessage]];
        }

        return ['message' => $message, 'twilio' => ['message_status' => $status]];
    }
}
