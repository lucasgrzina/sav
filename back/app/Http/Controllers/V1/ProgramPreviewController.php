<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\PreviewProgramRequest;
use App\Http\Resources\V1\ProgramPreviewResource;
use App\Services\ProgramAlerts\ProgramAlertPreviewService;
use Illuminate\Http\JsonResponse;

/** Read-only "Revisá los avisos" calculation. Persists, queues and notifies nothing. */
class ProgramPreviewController extends Controller
{
    public function __construct(private ProgramAlertPreviewService $previewService) {}

    public function preview(PreviewProgramRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $preview = $this->previewService->previewDraft($request->validated(), $vet);

            return $this->makeSuccess(new ProgramPreviewResource($preview));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function previewForProgram(PreviewProgramRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $program = $request->program();

            if ($program === null) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            if (! $program->editable) {
                return $this->makeError(['reason' => 'not_editable'], 'El programa está cancelado y no puede modificarse.', 422);
            }

            $preview = $this->previewService->previewDraft($request->validated(), $vet, $program);

            return $this->makeSuccess(new ProgramPreviewResource($preview));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
