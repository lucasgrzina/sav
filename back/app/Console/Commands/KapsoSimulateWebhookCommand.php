<?php

namespace App\Console\Commands;

use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\OptOut;
use App\Notifications\Models\WhatsappWebhookEvent;
use App\Notifications\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Posts a correctly signed delivery-status webhook at the local endpoint, so the whole
 * inbound path — signature, deduplication, monotonic transitions, asynchronous fallback —
 * can be exercised without a public tunnel. The tunnel only ever validates the Kapso→app
 * hop; everything that can actually be wrong lives on this side of it.
 */
class KapsoSimulateWebhookCommand extends Command
{
    protected $signature = 'kapso:simulate-webhook
        {wamid : El message id devuelto por kapso:send-test (wamid....)}
        {--event=delivered : sent | delivered | read | failed | received}
        {--code=131047 : Código de error de Meta, solo para --event=failed}
        {--title=Re-engagement message : Detalle del error, solo para --event=failed}
        {--from= : Teléfono remitente, solo con --event=received}
        {--text=BAJA : Cuerpo del mensaje entrante, solo con --event=received}
        {--idempotency-key= : Repetir la misma clave para probar la deduplicación}
        {--url= : Endpoint destino (por defecto la ruta local webhooks.kapso)}';

    protected $description = 'Simula un webhook de estado (o de mensaje entrante) de Kapso contra el endpoint local, firmado con KAPSO_WEBHOOK_SECRET.';

    private const EVENTS = ['sent', 'delivered', 'read', 'failed', 'received'];

    public function handle(): int
    {
        $secret = (string) config('notifications.kapso.webhook_secret');

        if ($secret === '') {
            $this->error('Falta KAPSO_WEBHOOK_SECRET en el .env: es la clave con la que se firma.');

            return Command::FAILURE;
        }

        $event = (string) $this->option('event');

        if (! in_array($event, self::EVENTS, true)) {
            $this->error('Evento inválido. Disponibles: ' . implode(', ', self::EVENTS));

            return Command::FAILURE;
        }

        $wamid = (string) $this->argument('wamid');
        $eventType = "whatsapp.message.{$event}";
        $url = (string) ($this->option('url') ?: route('webhooks.kapso'));

        // The body is signed and sent as the SAME string: re-encoding between signing and
        // sending is exactly the bug this tool exists to rule out.
        $body = json_encode(
            $this->payload($eventType, $wamid, $event),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $idempotencyKey = (string) ($this->option('idempotency-key') ?: Str::uuid()->toString());

        $this->line("POST {$url}");
        $this->line("  evento: {$eventType}");
        $this->line("  wamid: {$wamid}");

        $response = Http::withHeaders([
            'X-Webhook-Signature' => hash_hmac('sha256', $body, $secret),
            'X-Webhook-Event' => $eventType,
            'X-Idempotency-Key' => $idempotencyKey,
            'X-Webhook-Payload-Version' => 'v2',
        ])
            ->acceptJson()
            ->withBody($body, 'application/json')
            ->timeout(15)
            ->post($url);

        $this->newLine();
        $this->line("HTTP {$response->status()}: " . trim($response->body()));

        if ($response->status() === 401) {
            $this->error('401: el endpoint calculó otra firma. Revisá que KAPSO_WEBHOOK_SECRET sea el mismo que ve la app que atiende.');

            return Command::FAILURE;
        }

        if ($response->failed()) {
            return Command::FAILURE;
        }

        $this->reportEffect($wamid, $idempotencyKey, $event);

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function payload(string $eventType, string $wamid, string $event): array
    {
        if ($event === 'received') {
            return [
                'type' => $eventType,
                'message' => [
                    'id' => $wamid,
                    'from' => (string) $this->option('from'),
                    'text' => ['body' => (string) $this->option('text')],
                ],
            ];
        }

        $message = ['id' => $wamid, 'to' => '5490000000000'];

        if ($event === 'failed') {
            $message['errors'] = [[
                'code' => (int) $this->option('code'),
                'title' => (string) $this->option('title'),
            ]];
        }

        return ['type' => $eventType, 'message' => $message];
    }

    /** The response only says "accepted"; what matters is what it changed. */
    private function reportEffect(string $wamid, string $idempotencyKey, string $event): void
    {
        $this->newLine();

        $webhookEvent = WhatsappWebhookEvent::where('idempotency_key', $idempotencyKey)->first();

        if ($webhookEvent === null) {
            $this->warn('No se encontró la fila en whatsapp_webhook_events (¿duplicado?).');
        } else {
            $this->line('whatsapp_webhook_events:');
            $this->line('  processed_at: ' . ($webhookEvent->processed_at?->toDateTimeString() ?? '(pendiente)'));
            $this->line('  outcome: ' . ($webhookEvent->outcome ?? '—'));
            $this->line('  error: ' . ($webhookEvent->error ?? '—'));
        }

        // An inbound message has no provider_message_id correlatable to any alert_recipient
        // — its wamid is the id of the message the CLIENT sent — so the effect to check is
        // the opt_outs row, not a recipient.
        if ($event === 'received') {
            $this->reportInboundEffect();

            return;
        }

        $recipient = AlertRecipient::where('provider_message_id', $wamid)->first();

        if ($recipient === null) {
            $this->newLine();
            $this->warn("Ningún alert_recipient tiene provider_message_id = {$wamid}.");
            $this->line('Es esperable si el wamid no salió de un envío real de esta base.');

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

    private function reportInboundEffect(): void
    {
        $phone = PhoneNumber::normalize((string) $this->option('from'));

        $this->newLine();

        if ($phone === '') {
            $this->warn('Sin --from: no hay teléfono que buscar en opt_outs.');

            return;
        }

        $optOut = OptOut::where('phone', $phone)->where('channel', 'whatsapp')->first();

        $this->line('opt_outs:');
        $this->line('  phone: ' . $phone);
        $this->line($optOut !== null ? '  estado: dado de baja' : '  estado: sin baja registrada');
    }
}
