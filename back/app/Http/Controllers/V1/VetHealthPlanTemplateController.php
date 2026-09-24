<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\HealthPlanTemplateLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthPlanTemplateRequest;
use App\Http\Requests\Health\StoreHealthPlanTemplateRequest;
use App\Http\Requests\Health\UpdateHealthPlanTemplateRequest;
use App\Http\Resources\V1\HealthPlanTemplateListResource;
use App\Http\Resources\V1\HealthPlanTemplateResource;
use App\Services\HealthPlanTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetHealthPlanTemplateController extends Controller
{
    public function __construct(private HealthPlanTemplateService $service) {}

    public function index(IndexHealthPlanTemplateRequest $request): JsonResponse
    {
        try {
            $vet       = $request->attributes->get('current_vet');
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginateForVet($vet->id, $request->validated(), $perPage);

            return $this->makeSuccessPagination($paginator, HealthPlanTemplateListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $vet      = $request->attributes->get('current_vet');
            $template = $this->service->findByGuidForVetScope($request->route('guid'), $vet->id);

            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }

            return $this->makeSuccess(new HealthPlanTemplateResource($template));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreHealthPlanTemplateRequest $request): JsonResponse
    {
        try {
            $vet      = $request->attributes->get('current_vet');
            $template = $this->service->createForVet($request->validated(), $vet->id);

            return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla creada correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateHealthPlanTemplateRequest $request): JsonResponse
    {
        try {
            $vet      = $request->attributes->get('current_vet');
            $guid     = $request->route('guid');
            $template = $this->service->findOwnByGuidForVet($guid, $vet->id);

            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }

            $template = $this->service->updateOwnedByVet($template, $request->validated());

            return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla actualizada correctamente.');
        } catch (HealthPlanTemplateLockedException $e) {
            return $this->makeError(['reason' => 'template_locked'], $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $vet      = $request->attributes->get('current_vet');
            $guid     = $request->route('guid');
            $template = $this->service->findOwnByGuidForVet($guid, $vet->id);

            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }

            $this->service->destroyOwnedByVet($template);

            return $this->makeSuccess(null, 'Plantilla eliminada correctamente.');
        } catch (HealthPlanTemplateLockedException $e) {
            return $this->makeError(['reason' => 'template_locked'], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
