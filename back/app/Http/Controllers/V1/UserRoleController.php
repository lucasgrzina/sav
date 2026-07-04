<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignRolesRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function __construct(
        private UserService $userService,
    ) {}

    public function sync(AssignRolesRequest $request, string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $user = $this->userService->syncRoles($user, $request->validated()['roles']);

            return $this->makeSuccess(new UserResource($user), 'Roles asignados correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
