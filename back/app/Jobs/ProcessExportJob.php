<?php

namespace App\Jobs;

use App\Events\ExportCompletedEvent;
use App\Events\ExportFailedEvent;
use App\Events\ExportStartedEvent;
use App\Models\Export;
use App\Services\Exports\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessExportJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300; // 5 minutos máximo

    public function __construct(
        public readonly int $exportId,
    ) {}

    public function handle(ExportService $exportService): void
    {
        $export = Export::findOrFail($this->exportId);

        try {
            event(new ExportStartedEvent($export));
            $completed = $exportService->process($export);
            event(new ExportCompletedEvent($completed));
        } catch (\Throwable $e) {
            // El ExportService ya actualizó el status a FAILED.
            // Solo disparamos el evento de fallo.
            event(new ExportFailedEvent($export->fresh(), $e->getMessage()));
            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessExportJob falló definitivamente', [
            'export_id' => $this->exportId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
