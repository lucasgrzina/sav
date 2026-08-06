<?php

namespace App\Console\Commands;

use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\WhatsappWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Twilio\Security\RequestValidator;

/**
 * Posts a correctly signed Twilio Status Callback at the local endpoint, so the whole
 * inbound path — signature, deduplication, monotonic transitions, asynchronous fallback —
 * can be exercised without waiting on a real message to actually change status on Twilio's
 * side. Mirrors kapso:simulate-webhook; uses the SDK's own RequestValidator::computeSignature()
 * to sign, so this tool never reimplements Twilio's signing algorithm either.
 */
class TwilioSimulateWebhookCommand extends Command
{
    protected $signature = 'twilio:simulate-webhook
        {sid : El MessageSid devuelto por un envío real o de prueba (SM....)}
        {--status=delivered : queued | sending | sent | delivered | read | failed | undelivered}
        {--error-code= : Código de error de Twilio, solo con --status=failed|undelivered}
        {--error-message= : Detalle del error, solo con --status=failed|undelivered}
        {--to= : Número destino en formato whatsapp:+E.164 (solo informativo en el payload)}
        {--url= : Endpoint destino (por defecto la ruta local webhooks.twilio)}';

    protected $description = 'Simula un Status Callback de Twilio contra el endpoint local, firmado con TWILIO_AUTH_TOKEN.';

    private const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'read', 'failed', 'undelivered'];

    public function handle(): int
    {
        $token = (string) config('notifications.twilio.token');

        if ($token === '') {
            $this->error('Falta TWILIO_AUTH_TOKEN en el .env: es la clave con la que se firma.');

            return Command::FAILURE;
        }

        $status = (string) $this->option('status');

        if (! in_array($status, self::STATUSES, true)) {
            $this->error('Status inválido. Disponibles: ' . implode(', ', self::STATUSES));

            return Command::FAILURE;
        }

        $sid = (string) $this->argument('sid');
        $url = (string) ($this->option('url') ?: route('webhooks.twilio'));

        $params = $this->params($sid, $status);

        // Twilio signs the exact URL it calls plus the sorted POST params — computeSignature()
        // is the same code path RequestValidator::validate() runs on the receiving end, so
        // signing here and verifying there can never drift apart because of a hand-rolled
        // reimplementation on either side.
        $signature = (new RequestValidator($token))->computeSignature($url, $params);

        $this->line("POST {$url}");
        $this->line("  MessageSid: {$sid}");
        $this->line("  MessageStatus: {$status}");

        $response = Http::withHeaders(['X-Twilio-Signature' => $signature])
            ->asForm()
            ->timeout(15)
            ->post($url, $params);

        $this->newLine();
        $this->line("HTTP {$response->status()}: " . trim($response->body()));

        if ($response->status() === 401) {
            $this->error('401: el endpoint calculó otra firma. Revisá que TWILIO_AUTH_TOKEN sea el mismo que ve la app que atiende, y que la URL coincida byte a byte (esquema/host/puerto) con la que ve Laravel detrás de un túnel.');

            return Command::FAILURE;
        }

        if ($response->failed()) {
            return Command::FAILURE;
        }

        $this->reportEffect($sid, $status);

        return Command::SUCCESS;
    }

    /** @return array<string, string> */
    private function params(string $sid, string $status): array
    {
        $params = [
            'MessageSid' => $sid,
            'MessageStatus' => $status,
            'To' => (string) ($this->option('to') ?: 'whatsapp:+5490000000000'),
            'From' => 'whatsapp:+14155238886',
        ];

        $errorCode = $this->option('error-code');
        $errorMessage = $this->option('error-message');

        if ($errorCode !== null) {
            $params['ErrorCode'] = (string) $errorCode;
        }

        if ($errorMessage !== null) {
            $params['ErrorMessage'] = (string) $errorMessage;
        }

        return $params;
    }

    /** The response only says 204; what matters is what it changed. */
    private function reportEffect(string $sid, string $status): void
    {
        $this->newLine();

        $idempotencyKey = "twilio:{$sid}:{$status}";
        $webhookEvent = WhatsappWebhookEvent::where('idempotency_key', $idempotencyKey)->first();

        if ($webhookEvent === null) {
            $this->warn('No se encontró la fila en whatsapp_webhook_events (¿duplicado?).');
        } else {
            $this->line('whatsapp_webhook_events:');
            $this->line('  provider: ' . $webhookEvent->provider);
            $this->line('  event_type: ' . $webhookEvent->event_type);
            $this->line('  processed_at: ' . ($webhookEvent->processed_at?->toDateTimeString() ?? '(pendiente)'));
            $this->line('  outcome: ' . ($webhookEvent->outcome ?? '—'));
            $this->line('  error: ' . ($webhookEvent->error ?? '—'));
        }

        $recipient = AlertRecipient::where('provider_message_id', $sid)->first();

        if ($recipient === null) {
            $this->newLine();
            $this->warn("Ningún alert_recipient tiene provider_message_id = {$sid}.");
            $this->line('Es esperable si el sid no salió de un envío real de esta base.');

            return;
        }

        $this->newLine();
        $this->line('alert_recipients:');
        $this->line('  channel: ' . $recipient->channel->value);
        $this->line('  status: ' . $recipient->status->value);
        $this->line('  delivered_at: ' . ($recipient->delivered_at?->toDateTimeString() ?? '—'));
        $this->line('  failure_reason: ' . ($recipient->failure_reason ?? '—'));

        $fallbacks = AlertRecipient::where('alert_id', $recipient->alert_id)
            ->where('user_profile_id', $recipient->user_profile_id)
            ->where('id', '!=', $recipient->id)
            ->get();

        foreach ($fallbacks as $fallback) {
            $this->line("  fallback → {$fallback->channel->value}: {$fallback->status->value}");
        }
    }
}
