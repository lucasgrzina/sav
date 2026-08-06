<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Registers (or re-points) the delivery-status webhook for the configured phone number.
 *
 * The point of doing this from artisan rather than by hand is that the secret is read from
 * config instead of retyped: a WhatsApp webhook's secret_key is chosen by the caller, so a
 * mismatch between what Kapso signs with and what KAPSO_WEBHOOK_SECRET holds produces a
 * permanent 401 that looks exactly like a code bug.
 */
class KapsoRegisterWebhookCommand extends Command
{
    protected $signature = 'kapso:register-webhook
        {url : URL pública HTTPS del endpoint (ej. https://xxxx.trycloudflare.com/api/v1/webhooks/kapso)}
        {--update : Re-apunta el webhook existente de este número en lugar de crear uno nuevo}
        {--id= : UUID del webhook a actualizar, cuando hay más de uno para el mismo número}
        {--dry-run : Muestra el payload sin tocar nada}';

    protected $description = 'Registra o actualiza en Kapso el webhook de estados de entrega usando KAPSO_WEBHOOK_SECRET.';

    /**
     * Delivery-status events plus the inbound message event: `whatsapp.message.received`
     * feeds RecordInboundOptOut (opt-out/opt-in keyword detection), not RecordDeliveryStatus.
     */
    private const EVENTS = [
        'whatsapp.message.received',
        'whatsapp.message.sent',
        'whatsapp.message.delivered',
        'whatsapp.message.read',
        'whatsapp.message.failed',
    ];

    /** Guard against paginating forever if the API keeps reporting more pages. */
    private const MAX_PAGES = 20;

    public function handle(): int
    {
        $config = config('notifications.kapso');
        $url = (string) $this->argument('url');

        foreach (['api_key' => 'KAPSO_API_KEY', 'phone_number_id' => 'KAPSO_PHONE_NUMBER_ID', 'webhook_secret' => 'KAPSO_WEBHOOK_SECRET'] as $key => $env) {
            if (blank($config[$key] ?? null)) {
                $this->error("Falta {$env} en el .env.");

                return Command::FAILURE;
            }
        }

        if (! str_starts_with($url, 'https://')) {
            $this->error('La URL debe ser HTTPS: Kapso no entrega webhooks a HTTP.');

            return Command::FAILURE;
        }

        $body = [
            'url' => $url,
            // 'kapso' entrega eventos estructurados (whatsapp.message.*); 'meta' reenviaría
            // el payload crudo de Meta, que no es lo que parsea el handler.
            'kind' => 'kapso',
            'secret_key' => (string) $config['webhook_secret'],
            'active' => true,
            'events' => self::EVENTS,
            // El buffering agrupa varios eventos en un request; el handler resuelve un
            // message id por payload, así que se deja explícitamente apagado.
            'buffer_enabled' => false,
        ];

        $webhookId = $this->option('id') ? (string) $this->option('id') : null;

        if ($this->option('update') && $webhookId === null) {
            $webhookId = $this->resolveExistingWebhookId($config);

            if ($webhookId === false) {
                return Command::FAILURE;
            }

            if ($webhookId === null) {
                $this->warn('No hay webhook previo para este número: se va a crear uno nuevo.');
            }
        }

        // PATCH no acepta phone_number_id: el webhook ya está atado a su número.
        if ($webhookId === null) {
            $body['phone_number_id'] = (string) $config['phone_number_id'];
        }

        $payload = ['whatsapp_webhook' => $body];

        if ($this->option('dry-run')) {
            $this->line($webhookId === null
                ? 'POST ' . $this->endpoint($config)
                : 'PATCH ' . $this->endpoint($config) . '/' . $webhookId);
            $this->line(json_encode(
                $this->redact($payload),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return Command::SUCCESS;
        }

        $response = $webhookId === null
            ? $this->client($config)->post($this->endpoint($config), $payload)
            : $this->client($config)->patch($this->endpoint($config) . '/' . $webhookId, $payload);

        if ($response->failed()) {
            $verb = $webhookId === null ? 'registro' : 'update';
            $this->error("Kapso rechazó el {$verb} ({$response->status()}): " . $this->errorFrom($response));

            return Command::FAILURE;
        }

        $this->info($webhookId === null ? 'Webhook registrado.' : 'Webhook actualizado.');
        $this->line('  id: ' . (string) $response->json('data.id'));
        $this->line('  url: ' . (string) $response->json('data.url'));
        $this->line('  payload_version: ' . (string) $response->json('data.payload_version'));
        $this->line('  events: ' . implode(', ', (array) $response->json('data.events')));

        // The secret is what the signature middleware validates against; a silent mismatch
        // would surface later as a permanent 401 on every delivery.
        $returned = $response->json('data.secret_key');

        if ($returned !== null && $returned !== (string) $config['webhook_secret']) {
            $this->warn('El secret que devolvió Kapso NO coincide con KAPSO_WEBHOOK_SECRET: la firma va a fallar con 401.');
        }

        return Command::SUCCESS;
    }

    /**
     * The list endpoint has no phone_number_id filter, so the match is done here.
     *
     * @param array<string, mixed> $config
     * @return string|null|false The webhook id, null when there is none, false on an error already reported.
     */
    private function resolveExistingWebhookId(array $config): string|null|false
    {
        $phoneNumberId = (string) $config['phone_number_id'];
        $matches = [];
        $page = 1;

        do {
            $response = $this->client($config)->get($this->endpoint($config), [
                'kind' => 'kapso',
                'page' => $page,
                'per_page' => 100,
            ]);

            if ($response->failed()) {
                $this->error("No se pudo listar los webhooks ({$response->status()}): " . $this->errorFrom($response));

                return false;
            }

            foreach ((array) $response->json('data', []) as $webhook) {
                if ((string) ($webhook['phone_number_id'] ?? '') === $phoneNumberId) {
                    $matches[] = $webhook;
                }
            }

            $totalPages = (int) ($response->json('meta.total_pages') ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= self::MAX_PAGES);

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            $this->error('Hay más de un webhook para este número. Elegí uno con --id=<uuid>:');

            foreach ($matches as $webhook) {
                $this->line("  {$webhook['id']}  {$webhook['url']}");
            }

            return false;
        }

        $this->line("Webhook existente: {$matches[0]['id']} → {$matches[0]['url']}");

        return (string) $matches[0]['id'];
    }

    /** @param array<string, mixed> $config */
    private function client(array $config): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => (string) $config['api_key']])
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    /** @param array<string, mixed> $config */
    private function endpoint(array $config): string
    {
        return rtrim((string) $config['base_url'], '/') . '/platform/v1/whatsapp/webhooks';
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function redact(array $payload): array
    {
        $payload['whatsapp_webhook']['secret_key'] = '<KAPSO_WEBHOOK_SECRET>';

        return $payload;
    }

    private function errorFrom(Response $response): string
    {
        return (string) ($response->json('error.message')
            ?? $response->json('message')
            ?? $response->json('errors.0.detail')
            ?? $response->body());
    }
}
