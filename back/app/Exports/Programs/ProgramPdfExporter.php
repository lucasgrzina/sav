<?php

namespace App\Exports\Programs;

use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Services\ProgramService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el PDF de detalle de un programa: encabezado + datos del programa + tareas
 * agrupadas por ProgramTarget. No extiende BasePdfExporter (final, pensado para listados
 * tabulares homogéneos) — ver DEC-02 del plan.
 */
final class ProgramPdfExporter implements ExporterInterface
{
    public function __construct(
        private readonly ProgramRepositoryInterface $programRepository,
        private readonly ProgramService $programService,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        // Regla dura #4 (multi-tenant): siempre resuelto vía findByGuidForVet, nunca sin scope.
        $program = $this->programRepository->findByGuidForVet(
            (string) $filters['program_guid'],
            (int) $filters['vet_id'],
        );

        if ($program === null) {
            throw new \RuntimeException("Programa {$filters['program_guid']} no encontrado para este vet.");
        }

        $program->loadMissing('client', 'establishment', 'technique', 'protocol', 'targets.animals');
        $groups = $this->programService->projectTasksForPdf($program);

        $pdf = Pdf::loadView('exports.programs.detail', [
            'program' => $program,
            'groups'  => $groups,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($filePath, $pdf->output());

        return $filePath;
    }

    public function getExtension(): string
    {
        return 'pdf';
    }

    public function getMimeType(): string
    {
        return 'application/pdf';
    }
}
