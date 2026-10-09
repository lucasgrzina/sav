<?php

namespace App\Console\Commands;

use App\Services\ProgramManagerLinkService;
use Illuminate\Console\Command;

class ReconcileUnlinkedProgramManagersCommand extends Command
{
    protected $signature   = 'programs:reconcile-unlinked-managers {--dry-run : Only report, change nothing}';
    protected $description = 'Quita de los programas activos a los managers de cliente no vinculados a su establecimiento y regenera las alertas.';

    public function handle(ProgramManagerLinkService $links): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $links->reconcileUnlinkedManagers($dryRun);

        $profiles = array_sum(array_map(fn (array $r) => count($r['profile_ids']), $report));
        $verb     = $dryRun ? 'Se corregirían' : 'Se corrigieron';
        $this->info("{$verb} {$profiles} managers en " . count($report) . ' programas.');

        $failed = array_filter($report, fn (array $r) => $r['regenerated'] === false);
        if ($failed !== []) {
            $this->error('Falló la regeneración de alertas en ' . count($failed) . ' programas (ver log).');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
