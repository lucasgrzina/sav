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

        $this->notify(new ExportStartedEvent($export));

        try {
            $completed = $exportService->process($export);
        } catch (\Throwable $e) {
            // El ExportService ya actualizó el status a FAILED.
            // Solo disparamos el evento de fallo.
            $this->notify(new ExportFailedEvent($export->fresh(), $e->getMessage()));
            $this->fail($e);

            return;
        }

        $this->notify(new ExportCompletedEvent($completed));
    }

    /**
     * Un fallo al notificar nunca debe contar como fallo del export.
     */
    private function notify(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('No se pudo despachar la notificación del export', [
                'export_id' => $this->exportId,
                'event'     => $event::class,
                'error'     => $e->getMessage(),
            ]);
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
