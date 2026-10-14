<?php

namespace App\Notifications\Scheduling;

use App\Notifications\Support\AlertsSchemaGuard;
use Illuminate\Console\Command;

/** Deploy / monitoring check: exit code 1 while the Fase 3 SQL scripts are not applied. */
class CheckAlertsSchema extends Command
{
    protected $signature = 'alerts:schema-check';

    protected $description = 'Verifica que el esquema de alertas (scripts SQL de la Fase 3) esté aplicado.';

    public function handle(AlertsSchemaGuard $schema): int
    {
        $missing = $schema->missing();

        if ($missing !== []) {
            $this->error('Alerts schema out of date. Missing: '.implode(', ', $missing));

            return Command::FAILURE;
        }

        $this->info('Alerts schema OK.');

        return Command::SUCCESS;
    }
}
