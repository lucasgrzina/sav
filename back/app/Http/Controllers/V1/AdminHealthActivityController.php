<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthActivityRequest;
use App\Http\Requests\Health\StoreHealthActivityRequest;
use App\Http\Requests\Health\UpdateHealthActivityRequest;
use App\Http\Resources\V1\HealthActivityResource;
use App\Services\HealthActivityService;
use Illuminate\Http\JsonResponse;

class AdminHealthActivityController extends Controller
{
    public function __construct(private HealthActivityService $service) {}

    public function index(IndexHealthActivityRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, HealthActivityResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreHealthActivityRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.create')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $activity = $this->service->create($request->validated());
            return $this->makeSuccess(new HealthActivityResource($activity), 'Actividad creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateHealthActivityRequest $request, string $guid): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.update')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $activity = $this->service->findByGuid($guid);
            if (!$activity) {
                return $this->makeNotFound('Actividad no encontrada.');
            }
            $activity = $this->service->update($activity, $request->validated());
            return $this->makeSuccess(new HealthActivityResource($activity), 'Actividad actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid, \Illuminate\Http\Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.delete')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $activity = $this->service->findByGuid($guid);
            if (!$activity) {
                return $this->makeNotFound('Actividad no encontrada.');
            }
            $this->service->destroy($activity);
            return $this->makeSuccess(null, 'Actividad eliminada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
