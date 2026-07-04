<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\InitiateExportRequest;
use App\Http\Resources\V1\ExportResource;
use App\Models\Export;
use App\Services\Exports\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exportService,
    ) {}

    public function store(InitiateExportRequest $request): JsonResponse
    {
        try {
            //$this->authorize('create', [Export::class, $request->validated('type')]);

            $export = $this->exportService->initiate(
                user:       $request->user(),
                exportType: $request->validated('type'),
                format:     $request->validated('format'),
                filters:    $request->validated('filters', []),
                columns:    $request->validated('columns', []),
                async:      (bool) $request->validated('async', false),
            );

            $code = $request->validated('async', false) ? 202 : 200;

            return $this->makeSuccess(new ExportResource($export), 'Exportación iniciada.', $code);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            //$this->authorize('viewAny', Export::class);

            $perPage   = (int) $request->get('per_page', 15);
            $paginator = $this->exportService->listForUser($request->user(), $perPage);

            return $this->makeSuccessPagination($paginator, ExportResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $export = $this->exportService->findByGuid($guid);

            if (! $export) {
                return $this->makeNotFound('Exportación no encontrada.');
            }

            $this->authorize('download', $export);

            return $this->makeSuccess(new ExportResource($export));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function download(string $guid)
    {
        try {
            $export = $this->exportService->findByGuid($guid);

            if (! $export) {
                return $this->makeNotFound('Exportación no encontrada.');
            }

            //$this->authorize('download', $export);

            if (! $export->isDownloadable()) {
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
}
