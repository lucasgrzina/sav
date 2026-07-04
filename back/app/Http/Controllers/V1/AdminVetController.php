<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\AdminAssignStaffRequest;
use App\Http\Requests\Members\AdminChangeStaffRoleRequest;
use App\Http\Requests\Members\UpdateVetStaffRequest;
use App\Http\Requests\Vets\IndexVetRequest;
use App\Http\Requests\Vets\StoreVetRequest;
use App\Http\Requests\Vets\UpdateVetRequest;
use App\Http\Resources\V1\ClientResource;
use App\Http\Resources\V1\UserProfileResource;
use App\Http\Resources\V1\VetResource;
use App\Services\ClientService;
use App\Services\UserProfileService;
use App\Services\VetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVetController extends Controller
{
    public function __construct(
        private VetService         $vetService,
        private ClientService      $clientService,
        private UserProfileService $userProfileService,
    ) {}

    public function index(IndexVetRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $perPage = $request->integer('per_page', 15);

            $paginator = $this->vetService->paginate($filters, $perPage);

            return $this->makeSuccessPagination($paginator, VetResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreVetRequest $request): JsonResponse
    {
        try {
            $vet = $this->vetService->create($request->validated());

            return $this->makeSuccess(new VetResource($vet), 'Veterinaria creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $vet->load(['country', 'documentType', 'validatedBy', 'contacts']);

            return $this->makeSuccess(new VetResource($vet));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateVetRequest $request, string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $vet = $this->vetService->update($vet, $request->validated());

            return $this->makeSuccess(new VetResource($vet), 'Veterinaria actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function validate(Request $request, string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            if ($vet->validated_at) {
                return $this->makeError(null, 'La veterinaria ya está validada.', 422);
            }

            // Llama al método validate() del VetService (no de Laravel).
            $vet = $this->vetService->validate($vet, $request->user());

            return $this->makeSuccess(new VetResource($vet), 'Veterinaria validada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function suspend(string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            if ($vet->suspended_at) {
                return $this->makeError(null, 'La veterinaria ya está suspendida.', 422);
            }

            $vet = $this->vetService->suspend($vet);

            return $this->makeSuccess(new VetResource($vet), 'Veterinaria suspendida correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function unsuspend(string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            if (!$vet->suspended_at) {
                return $this->makeError(null, 'La veterinaria no está suspendida.', 422);
            }

            $vet = $this->vetService->unsuspend($vet);

            return $this->makeSuccess(new VetResource($vet), 'Veterinaria reactivada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function clients(Request $request, string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $perPage   = $request->integer('per_page', 15);
            $filters   = $request->only(['search']);
            $paginator = $this->clientService->paginate($vet, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, ClientResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffIndex(string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $members    = $this->userProfileService->list($vet);
            $staffRoles = UserProfileService::VET_STAFF_ROLES;
            $staff      = $members->filter(fn ($profile) => in_array($profile->role->name, $staffRoles));

            return $this->makeSuccess(UserProfileResource::collection($staff));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffStore(AdminAssignStaffRequest $request, string $guid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $data = $request->validated();

            $user = $this->userProfileService->resolveUser($data['user_guid']);
            if (!$user) {
                return $this->makeNotFound('Usuario no encontrado.');
            }

            $role = $this->userProfileService->resolveRole($data['role_guid']);
            if (!$role) {
                return $this->makeNotFound('Rol no encontrado.');
            }

            $profile = $this->userProfileService->addMember($vet, $user, $role);

            return $this->makeSuccess(new UserProfileResource($profile), 'Miembro agregado correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffChangeRole(AdminChangeStaffRoleRequest $request, string $guid, string $profileGuid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            $role = $this->userProfileService->resolveRole($request->validated()['role_guid']);
            if (!$role) {
                return $this->makeNotFound('Rol no encontrado.');
            }

            $profile = $this->userProfileService->changeRole($profile, $role);

            return $this->makeSuccess(new UserProfileResource($profile), 'Rol actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffDestroy(string $guid, string $profileGuid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            $this->userProfileService->removeMember($profile);

            return $this->makeSuccess(null, 'Miembro eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffShow(string $guid, string $profileGuid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            $profile->load(['user', 'role', 'contacts']);

            return $this->makeSuccess(new UserProfileResource($profile));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffUpdate(UpdateVetStaffRequest $request, string $guid, string $profileGuid): JsonResponse
    {
        try {
            $vet = $this->vetService->findByGuid($guid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            $data    = $request->validated();
            $profile = $this->userProfileService->updateMember(
                $profile,
                $data['role_guid'],
                $data['contacts'] ?? [],
            );

            return $this->makeSuccess(
                new UserProfileResource($profile),
                'Perfil actualizado correctamente.',
            );
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
