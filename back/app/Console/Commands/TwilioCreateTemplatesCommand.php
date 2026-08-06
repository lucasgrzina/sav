<?php

namespace App\Console\Commands;

use App\Notifications\Enums\AlertType;
use App\Notifications\Templates\WhatsappTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TwilioCreateTemplatesCommand extends Command
{
    protected $signature   = 'twilio:create-templates {--language=es : Código de idioma del template} {--dry-run : Muestra el payload sin crear nada}';
    protected $description = 'Crea en Twilio los Content templates de WhatsApp para cada AlertType y devuelve los contentSid listos para el .env.';

    private const ENDPOINT = 'https://content.twilio.com/v1/Content';

    /** Nombre del template por AlertType, y la env var donde va su contentSid resultante. */
    private const TEMPLATES = [
        'program.created'   => ['env' => 'TWILIO_TEMPLATE_PROGRAM_CREATED', 'friendly_name' => 'sav_program_created'],
        'program.cancelled' => ['env' => 'TWILIO_TEMPLATE_PROGRAM_CANCELLED', 'friendly_name' => 'sav_program_cancelled'],
        'program.task_due'  => ['env' => 'TWILIO_TEMPLATE_PROGRAM_TASK_DUE', 'friendly_name' => 'sav_program_task_due'],
    ];

    /**
     * El body y las variables salen de WhatsappTemplateCatalog, que es la única fuente de
     * la copy: así el template de Twilio y el de Kapso no pueden divergir entre sí ni de
     * la cantidad de variables que manda cada MessageBuilder.
     *
     * @return array<string, array{env: string, friendly_name: string, body: string, variables: array<string, string>}>
     */
    private function definitions(): array
    {
        $definitions = [];

        foreach (self::TEMPLATES as $type => $template) {
            $alertType = AlertType::from($type);

            $definitions[$type] = $template + [
                'body'      => WhatsappTemplateCatalog::for($alertType)['body'],
                'variables' => WhatsappTemplateCatalog::exampleVariables($alertType),
            ];
        }

        return $definitions;
    }

    public function handle(): int
    {
        $sid   = config('notifications.twilio.sid');
        $token = config('notifications.twilio.token');

        if (! $this->option('dry-run') && (blank($sid) || blank($token))) {
            $this->error('Faltan TWILIO_ACCOUNT_SID y/o TWILIO_AUTH_TOKEN en el .env.');

            return Command::FAILURE;
        }

        $created = [];
        $failed  = 0;

        foreach ($this->definitions() as $type => $definition) {
            $payload = $this->payloadFor($definition);

            if ($this->option('dry-run')) {
                $this->line("<comment>{$type}</comment>");
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                continue;
            }

            $response = Http::withBasicAuth($sid, $token)
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT, $payload);

            if ($response->failed()) {
                $failed++;
                $this->error("{$type}: {$this->errorFrom($response)}");

                continue;
            }

            $contentSid       = $response->json('sid');
            $created[$type]   = [$definition['env'], $contentSid];

            $this->info("{$type} → {$contentSid}");
        }

        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }

        if ($created !== []) {
            $this->newLine();
            $this->line('<comment>Pegá esto en tu .env:</comment>');

            foreach ($created as [$env, $contentSid]) {
                $this->line("{$env}={$contentSid}");
            }

            $this->newLine();
            $this->line('Después: php artisan config:clear');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param array{friendly_name: string, body: string, variables: array<string, string>} $definition */
    private function payloadFor(array $definition): array
    {
        return [
            'friendly_name' => $definition['friendly_name'],
            'language'      => $this->option('language'),
            'variables'     => $definition['variables'],
            'types'         => [
                'twilio/text' => ['body' => $definition['body']],
            ],
        ];
    }

    private function errorFrom(Response $response): string
    {
        $message = $response->json('message') ?? $response->body();
        $code    = $response->json('code');

        return $code ? "[{$code}] {$message}" : (string) $message;
    }
}
