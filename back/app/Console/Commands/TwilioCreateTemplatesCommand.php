<?php

namespace App\Console\Commands;

use App\Notifications\Enums\AlertType;
use App\Notifications\Templates\WhatsappTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TwilioCreateTemplatesCommand extends Command
{
    protected $signature   = 'twilio:create-templates {--language=es : Código de idioma del template} {--only=* : Crea solo estas plantillas (type o type@vN, repetible). Ej: --only=program.task_due@v2} {--dry-run : Muestra el payload sin crear nada}';
    protected $description = 'Crea en Twilio los Content templates de WhatsApp para cada AlertType y devuelve los contentSid listos para el .env.';

    private const ENDPOINT = 'https://content.twilio.com/v1/Content';

    /**
     * Nombre del template por AlertType, y la env var donde va su contentSid resultante.
     *
     * 'program.created' NO está acá: desde que lleva un botón call-to-action con URL
     * dinámica ({{3}}), su shape en la Content API de Twilio no es el `twilio/text` simple
     * que este comando autoprovisiona. Se gestiona a mano en el Content Composer, igual que
     * 'program.pdf_shared' (que tampoco está acá, por el mismo motivo con su header de
     * medio — ver DEC-07 en WhatsappTemplateCatalog).
     */
    private const TEMPLATES = [
        'program.cancelled' => ['env' => 'TWILIO_TEMPLATE_PROGRAM_CANCELLED', 'friendly_name' => 'sav_program_cancelled'],
        'program.task_due'  => ['env' => 'TWILIO_TEMPLATE_PROGRAM_TASK_DUE', 'friendly_name' => 'sav_program_task_due'],
        'program.task_due@v2' => ['env' => 'TWILIO_TEMPLATE_PROGRAM_TASK_DUE_V2', 'friendly_name' => 'sav_program_task_due_v2'],
        'health_plan.month' => ['env' => 'TWILIO_TEMPLATE_HEALTH_PLAN_MONTH', 'friendly_name' => 'sav_health_plan_month'],
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

        foreach (self::TEMPLATES as $key => $template) {
            [$type, $version] = array_pad(explode('@v', $key, 2), 2, '1');
            $alertType = AlertType::from($type);
            $catalog   = WhatsappTemplateCatalog::for($alertType, (int) $version);

            // `twilio/text` has no footer component, so the footer is appended to the body
            // (same layout WhatsappTemplateCatalog::render() produces).
            $body = isset($catalog['footer']) ? $catalog['body'] . "\n\n" . $catalog['footer'] : $catalog['body'];

            $definitions[$key] = $template + [
                'body'      => $body,
                'variables' => WhatsappTemplateCatalog::exampleVariables($alertType, (int) $version),
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

        $only    = array_map('strval', (array) $this->option('only'));
        $unknown = array_diff($only, array_keys(self::TEMPLATES));

        if ($unknown !== []) {
            $this->error('--only desconocido: ' . implode(', ', $unknown) . '. Disponibles: ' . implode(', ', array_keys(self::TEMPLATES)));

            return Command::FAILURE;
        }

        foreach ($this->definitions() as $type => $definition) {
            if ($only !== [] && ! in_array($type, $only, true)) {
                continue;
            }

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
