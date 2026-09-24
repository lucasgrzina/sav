<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthPlanCategoryRequest;
use App\Http\Resources\V1\HealthPlanCategoryResource;
use App\Services\HealthPlanCategoryService;
use Illuminate\Http\JsonResponse;

/**
 * Solo lectura, panel tenant vet. `HealthPlanCategory` es catálogo global (DEC-NEG-03):
 * el vet lo consume para armar sus propias plantillas, nunca lo crea/edita/elimina.
 * Gateado por `establishment-health-plans.read`, ya otorgado a todos los roles tenant.
 */
class VetHealthPlanCategoryController extends Controller
{
    public function __construct(private HealthPlanCategoryService $service) {}

    public function index(IndexHealthPlanCategoryRequest $request): JsonResponse
    {
        try {
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);

            return $this->makeSuccessPagination($paginator, HealthPlanCategoryResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
