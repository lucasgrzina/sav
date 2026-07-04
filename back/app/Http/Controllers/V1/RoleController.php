<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\CreateRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\V1\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        private RoleService $roleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'type']);
            $perPage = (int) $request->get('per_page', 15);

            $paginator = $this->roleService->list($filters, $perPage);
            
            return $this->makeSuccessPagination(
                $paginator,
                RoleResource::class
            );
            
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(CreateRoleRequest $request): JsonResponse
    {
        try {
            $role = $this->roleService->create($request->validated());

            return $this->makeSuccess(new RoleResource($role), 'Rol creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $role = $this->roleService->findByGuid($guid);

            if (! $role) {
                return $this->makeNotFound('Rol no encontrado.');
            }

            return $this->makeSuccess(new RoleResource($role));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateRoleRequest $request, string $guid): JsonResponse
    {
        try {
            $role = $this->roleService->findByGuid($guid);

            if (! $role) {
                return $this->makeNotFound('Rol no encontrado.');
            }

            $role = $this->roleService->update($role, $request->validated());

            return $this->makeSuccess(new RoleResource($role), 'Rol actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid): JsonResponse
    {
        try {
            $role = $this->roleService->findByGuid($guid);

            if (! $role) {
                return $this->makeNotFound('Rol no encontrado.');
            }

            if (in_array($role->name, ['super-admin', 'admin'])) {
                return $this->makeError(null, 'Este rol no puede ser eliminado.', 403);
            }

            $this->roleService->destroy($role);

            return $this->makeSuccess(null, 'Rol eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
