<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthPlanCategoryRequest;
use App\Http\Requests\Health\StoreHealthPlanCategoryRequest;
use App\Http\Requests\Health\UpdateHealthPlanCategoryRequest;
use App\Http\Resources\V1\HealthPlanCategoryResource;
use App\Services\HealthPlanCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHealthPlanCategoryController extends Controller
{
    public function __construct(private HealthPlanCategoryService $service) {}

    public function index(IndexHealthPlanCategoryRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, HealthPlanCategoryResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreHealthPlanCategoryRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.create')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $category = $this->service->create($request->validated());
            return $this->makeSuccess(new HealthPlanCategoryResource($category), 'Categoría creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateHealthPlanCategoryRequest $request, string $guid): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.update')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $category = $this->service->findByGuid($guid);
            if (!$category) {
                return $this->makeNotFound('Categoría no encontrada.');
            }
            $category = $this->service->update($category, $request->validated());
            return $this->makeSuccess(new HealthPlanCategoryResource($category), 'Categoría actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid, Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.delete')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $category = $this->service->findByGuid($guid);
            if (!$category) {
                return $this->makeNotFound('Categoría no encontrada.');
            }
            $this->service->destroy($category);
            return $this->makeSuccess(null, 'Categoría eliminada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
