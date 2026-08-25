<?php

namespace App\Http\Controllers\V1;

use App\Enums\ExportFormat;
use App\Enums\ExportType;
use App\Exports\Programs\ProgramPdfExporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\ShareProgramPdfRequest;
use App\Http\Resources\V1\ExportResource;
use App\Http\Resources\V1\ProgramShareRecipientResource;
use App\Services\Exports\ExportService;
use App\Services\ProgramService;
use App\Services\ProgramShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProgramPdfController extends Controller
{
    public function __construct(
        private ProgramService $programService,
        private ExportService $exportService,
        private ProgramShareService $programShareService,
        private ProgramPdfExporter $programPdfExporter,
    ) {}

    /**
     * POST /v1/vets/{vet}/programs/{guid}/pdf — DEC-01: descarga async vía el módulo Export existente.
     *
     * $guid se lee de $request->route('guid'), nunca como parámetro de método tipado: la ruta
     * tiene DOS parámetros ({vet} y {guid}) y Laravel liga los parámetros de método sin
     * type-hint de clase por POSICIÓN, no por nombre — un método con solo `string $guid`
     * (sin `$vet`) recibiría el valor de {vet} en $guid. Mismo patrón que ProgramController.
     */
    public function requestPdf(Request $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $program = $this->programService->findByGuidForVet($guid, $vet->id);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $export = $this->exportService->initiate(
                user: $request->user(),
                exportType: ExportType::PROGRAM->value,
                format: ExportFormat::PDF->value,
                filters: ['program_guid' => $program->guid, 'vet_id' => $vet->id],
                async: true,
            );

            return $this->makeSuccess(new ExportResource($export), 'Generación de PDF iniciada.', 202);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** GET /v1/vets/{vet}/programs/{guid}/share-recipients — DEC-05: solo staff del lado cliente. */
    public function shareRecipients(Request $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $program = $this->programService->findByGuidForVet($guid, $vet->id);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $recipients = $this->programShareService->listClientRecipients($program);

            return $this->makeSuccess(ProgramShareRecipientResource::collection($recipients));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** POST /v1/vets/{vet}/programs/{guid}/share — DEC-06/DEC-09: envío por WhatsApp vía Alert/AlertRecipient. */
    public function share(ShareProgramPdfRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $program = $this->programService->findByGuidForVet($guid, $vet->id);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $export = $this->programShareService->getOrCreateShareableExport($program, $request->user());
            $alert = $this->programShareService->sendPdfToRecipients(
                $program,
                $export,
                $request->validated('manager_profile_ids'),
                $vet->id,
            );

            return $this->makeSuccess([
                'alert_guid' => $alert->guid,
                'recipients_count' => $alert->recipients()->count(),
            ], 'Envío iniciado.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** GET /v1/programs/shared-pdf/{guid} — DEC-08: ruta pública firmada, sin auth:sanctum. */
    public function servePublicPdf(string $guid)
    {
        try {
            $export = $this->exportService->findByGuid($guid);

            if (!$export) {
                return $this->makeNotFound('Exportación no encontrada.');
            }

            if (!$export->isDownloadable()) {
                return $this->makeError(null, 'La exportación no está disponible para descarga.', 422);
            }

            return response()->download(
                storage_path('app/private/' . $export->file_path),
                $export->file_name,
                ['Content-Type' => $export->format->mimeType()],
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/programs/{guid}/download-pdf — botón "Descargar el programa" del template de
     * ProgramCreated: el click llega desde WhatsApp, sin sesión de la app. guid y vet_id se
     * leen de la request (nunca parámetros tipados por posición, ver requestPdf() arriba).
     * Genera el PDF lazy, sin pasar por Export/ExportService (no hay User autenticado en
     * este contexto), y lo sirve directo, borrando el archivo temporal al enviarlo.
     */
    public function downloadPublic(Request $request)
    {
        try {
            $guid = $request->route('guid');
            $vetId = (int) $request->query('vet_id');
            $program = $this->programService->findByGuidForVet($guid, $vetId);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $filePath = 'exports/public/' . now()->format('Y-m') . '/' . Str::uuid()->toString() . '.pdf';

            $this->programPdfExporter->export(
                ['program_guid' => $program->guid, 'vet_id' => $vetId],
                [],
                $filePath,
            );

            return response()->download(
                storage_path('app/private/' . $filePath),
                "programa_{$program->guid}.pdf",
                ['Content-Type' => 'application/pdf'],
            )->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
