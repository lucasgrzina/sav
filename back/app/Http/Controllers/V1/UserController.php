<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AddUserProfileRequest;
use App\Http\Requests\Users\ChangeUserPasswordRequest;
use App\Http\Requests\Users\ChangeUserProfileRoleRequest;
use App\Http\Requests\Users\CreateTenantUserRequest;
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'status', 'date_from', 'date_to', 'vet_guid', 'type', 'role_guid']);
            $perPage = (int) $request->get('per_page', 15);

            $paginator = $this->userService->list($filters, $perPage);

            return $this->makeSuccessPagination(
                $paginator,
                UserResource::class
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        try {
            $user = $this->userService->create($request->validated());

            return $this->makeSuccess(new UserResource($user), 'Usuario creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function storeTenant(CreateTenantUserRequest $request): JsonResponse
    {
        try {
            $user = $this->userService->createTenantUser($request->validated());

            return $this->makeSuccess(new UserResource($user), 'Usuario de tenant creado correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $user->load(['roles', 'profiles.role', 'profiles.authenticatable']);

            return $this->makeSuccess(new UserResource($user));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateUserRequest $request, string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $user = $this->userService->update($user, $request->validated());

            return $this->makeSuccess(new UserResource($user), 'Usuario actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request, string $guid): JsonResponse
    {
        try {
            if ($request->user()->guid === $guid) {
                return $this->makeError(null, 'No podés eliminar tu propio usuario.', 403);
            }

            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $this->userService->destroy($user);

            return $this->makeSuccess(null, 'Usuario eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function toggleLock(string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $user = $this->userService->toggleLock($user);

            $message = $user->locked_at ? 'Usuario bloqueado.' : 'Usuario desbloqueado.';

            return $this->makeSuccess(new UserResource($user), $message);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function changePassword(ChangeUserPasswordRequest $request, string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $this->userService->changePassword($user, $request->validated()['password']);

            return $this->makeSuccess(null, 'Contraseña actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function storeProfile(AddUserProfileRequest $request, string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $user = $this->userService->addProfile($user, $request->validated());

            return $this->makeSuccess(new UserResource($user), 'Perfil agregado correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function changeProfileRole(ChangeUserProfileRoleRequest $request, string $guid, string $profileGuid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);
            if (!$user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }
            $user = $this->userService->changeProfileRole($user, $profileGuid, $request->validated()['role_guid']);
            return $this->makeSuccess(new UserResource($user), 'Rol actualizado correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function resetPassword(string $guid): JsonResponse
    {
        try {
            $user = $this->userService->findByGuid($guid);

            if (! $user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $newPassword = $this->userService->resetPassword($user);

            return $this->makeSuccess(['password' => $newPassword], 'Contraseña reseteada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
