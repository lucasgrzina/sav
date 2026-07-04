<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\ExportRepositoryInterface;
use Illuminate\Console\Command;

class ExportsCleanupCommand extends Command
{
    protected $signature   = 'exports:cleanup';
    protected $description = 'Elimina exportaciones expiradas y sus archivos del disco.';

    public function handle(ExportRepositoryInterface $exportRepository): int
    {
        $deleted = $exportRepository->deleteExpired();
        $this->info("Se eliminaron {$deleted} exportaciones expiradas.");

        return Command::SUCCESS;
    }
}
