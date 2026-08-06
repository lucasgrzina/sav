<?php

namespace App\Console\Commands;

use App\Notifications\Templates\WhatsappTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Creates the WhatsApp templates the alerts pipeline sends, in the Kapso project's WABA.
 *
 * Names and languages come from config('notifications.kapso.templates') and the copy from
 * WhatsappTemplateCatalog, which is the point: the template that lands in Meta is built
 * from the same declarations KapsoWhatsappGateway sends against, so they cannot disagree.
 */
class KapsoCreateTemplatesCommand extends Command
{
    protected $signature = 'kapso:create-templates
        {--business-account-id= : WABA id; por defecto se descubre desde KAPSO_PHONE_NUMBER_ID}
        {--category=UTILITY : AUTHENTICATION | MARKETING | UTILITY}
        {--dry-run : Muestra los payloads sin crear nada}';

    protected $description = 'Crea en Kapso los templates de WhatsApp de cada AlertType y reporta su estado de aprobación.';

    private const CATEGORIES = ['AUTHENTICATION', 'MARKETING', 'UTILITY'];

    public function handle(): int
    {
        $config = config('notifications.kapso');
        $category = strtoupper((string) $this->option('category'));

        if (! in_array($category, self::CATEGORIES, true)) {
            $this->error('Categoría inválida. Disponibles: ' . implode(', ', self::CATEGORIES));

            return Command::FAILURE;
        }

        if (! $this->option('dry-run') && blank($config['api_key'] ?? null)) {
            $this->error('Falta KAPSO_API_KEY en el .env.');

            return Command::FAILURE;
        }

        $payloads = $this->payloads($config, $category);

        if ($payloads === null) {
            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach ($payloads as $type => $payload) {
                $this->line("<comment>{$type}</comment>");
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return Command::SUCCESS;
        }

        $businessAccountId = $this->resolveBusinessAccountId($config);

        if ($businessAccountId === null) {
            return Command::FAILURE;
        }

        $endpoint = sprintf(
            '%s/meta/whatsapp/%s/%s/message_templates',
            rtrim((string) $config['base_url'], '/'),
            trim((string) $config['api_version'], '/'),
            $businessAccountId,
        );

        $failed = 0;

        foreach ($payloads as $type => $payload) {
            $response = $this->client($config)->post($endpoint, $payload);

            if ($response->failed()) {
                $failed++;
                $this->error("{$type}: " . $this->errorFrom($response));

                continue;
            }

            $status = (string) $response->json('status');
            $this->info("{$type} → {$payload['name']} (id {$response->json('id')}, {$status})");
        }

        $this->newLine();
        $this->line('Los templates quedan en PENDING hasta que Meta los apruebe; recién entonces sirven');
        $this->line('para mensajes iniciados por la empresa. Verificá con: php artisan kapso:send-test <telefono>');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>|null
     */
    private function payloads(array $config, string $category): ?array
    {
        $payloads = [];

        foreach (WhatsappTemplateCatalog::definitions() as $type => $definition) {
            $template = $config['templates'][$type] ?? null;
            $name = $template['name'] ?? null;

            if (! is_string($name) || $name === '') {
                $this->error("Sin nombre de template configurado para {$type} (revisá KAPSO_TEMPLATE_*).");

                return null;
            }

            $payloads[$type] = [
                'name' => $name,
                'language' => (string) ($template['language'] ?? 'es'),
                'category' => $category,
                // POSITIONAL, not NAMED: KapsoWhatsappGateway sends parameters by position.
                // A NAMED template would reject those sends.
                'parameter_format' => 'POSITIONAL',
                'components' => [[
                    'type' => 'BODY',
                    'text' => $definition['body'],
                    // Meta expects positional examples as an array of rows, one row per
                    // component instance — hence the extra nesting.
                    'example' => ['body_text' => [$definition['examples']]],
                ]],
            ];
        }

        return $payloads;
    }

    /**
     * The WABA id is not the phone number id, and asking for one more env var when the
     * platform API already maps the two is friction with no benefit.
     *
     * @param array<string, mixed> $config
     */
    private function resolveBusinessAccountId(array $config): ?string
    {
        foreach ([$this->option('business-account-id'), $config['business_account_id'] ?? null] as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        $phoneNumberId = (string) ($config['phone_number_id'] ?? '');

        if ($phoneNumberId === '') {
            $this->error('Falta KAPSO_PHONE_NUMBER_ID: sin él no se puede descubrir el WABA id.');

            return null;
        }

        $response = $this->client($config)
            ->get(rtrim((string) $config['base_url'], '/') . '/platform/v1/whatsapp/phone_numbers', ['per_page' => 100]);

        if ($response->failed()) {
            $this->error("No se pudo listar los números ({$response->status()}): " . $this->errorFrom($response));

            return null;
        }

        foreach ((array) $response->json('data', []) as $number) {
            if ((string) ($number['phone_number_id'] ?? '') !== $phoneNumberId) {
                continue;
            }

            if (blank($number['business_account_id'] ?? null)) {
                $this->error("El número {$phoneNumberId} no tiene business_account_id asociado en Kapso.");

                return null;
            }

            $businessAccountId = (string) $number['business_account_id'];
            $this->line("WABA descubierto para {$phoneNumberId}: {$businessAccountId}");

            return $businessAccountId;
        }

        $this->error("KAPSO_PHONE_NUMBER_ID={$phoneNumberId} no aparece en tu proyecto de Kapso.");
        $this->line('Pasá el WABA a mano con --business-account-id=<id> si el número está en otro proyecto.');

        return null;
    }

    /** @param array<string, mixed> $config */
    private function client(array $config): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => (string) $config['api_key']])
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    private function errorFrom(Response $response): string
    {
        $message = $response->json('error.error_user_msg')
            ?? $response->json('error.message')
            ?? $response->json('message')
            ?? $response->body();

        $code = $response->json('error.code');

        return $code === null ? (string) $message : "[{$code}] {$message}";
    }
}
