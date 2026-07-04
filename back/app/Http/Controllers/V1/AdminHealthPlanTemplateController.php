<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthPlanTemplateRequest;
use App\Http\Requests\Health\StoreHealthPlanTemplateRequest;
use App\Http\Requests\Health\UpdateHealthPlanTemplateRequest;
use App\Http\Resources\V1\HealthPlanTemplateListResource;
use App\Http\Resources\V1\HealthPlanTemplateResource;
use App\Services\HealthPlanTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHealthPlanTemplateController extends Controller
{
    public function __construct(private HealthPlanTemplateService $service) {}

    public function index(IndexHealthPlanTemplateRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, HealthPlanTemplateListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid, Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->findByGuid($guid);
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
            if (!$request->user()->can('health-plan-templates.create')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->create($request->validated());
            return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla creada correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateHealthPlanTemplateRequest $request, string $guid): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.update')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->findByGuid($guid);
            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }
            $template = $this->service->update($template, $request->validated());
            return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla actualizada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid, Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.delete')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->findByGuid($guid);
            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }
            $this->service->destroy($template);
            return $this->makeSuccess(null, 'Plantilla eliminada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
