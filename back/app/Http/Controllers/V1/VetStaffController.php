<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\AssignVetStaffRequest;
use App\Http\Requests\Members\ChangeVetStaffRoleRequest;
use App\Http\Requests\Members\CreateVetStaffRequest;
use App\Http\Requests\Members\LookupVetStaffRequest;
use App\Http\Requests\Members\UpdateVetStaffRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ContactService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetStaffController extends Controller
{
    public function __construct(
        private UserProfileService $userProfileService,
        private ContactService     $contactService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $vet     = $request->attributes->get('current_vet');
            $members = $this->userProfileService->list($vet);

            return $this->makeSuccess(UserProfileResource::collection($members));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(AssignVetStaffRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
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

            foreach ($data['contacts'] ?? [] as $contactData) {
                $this->contactService->create($profile, $contactData);
            }

            return $this->makeSuccess(
                new UserProfileResource($profile->load(['user', 'role', 'contacts'])),
                'Personal incorporado al equipo correctamente.',
                201
            );
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $guid           = $request->route('guid');
            $vet            = $request->attributes->get('current_vet');
            $currentProfile = $request->attributes->get('current_profile');

            $profile = $this->userProfileService->findByGuidForVet($guid, $vet);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            if ($currentProfile && $profile->id === $currentProfile->id) {
                return $this->makeError(null, 'No podés eliminarte a vos mismo del tenant.', 403);
            }

            $this->userProfileService->removeMember($profile);

            return $this->makeSuccess(null, 'Miembro eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function changeRole(ChangeVetStaffRoleRequest $request): JsonResponse
    {
        try {
            $guid    = $request->route('guid');
            $vet     = $request->attributes->get('current_vet');
            $profile = $this->userProfileService->findByGuidForVet($guid, $vet);

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

    public function lookup(LookupVetStaffRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $result = $this->userProfileService->lookupForVet(
                $request->validated()['email'],
                $vet
            );

            return $this->makeSuccess($result);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function createAndAssign(CreateVetStaffRequest $request): JsonResponse
    {
        try {
            $vet     = $request->attributes->get('current_vet');
            $profile = $this->userProfileService->createAndAssignVetStaff(
                $vet,
                $request->validated()
            );

            return $this->makeSuccess(
                new UserProfileResource($profile->load(['user', 'role', 'contacts'])),
                'Usuario creado e incorporado al equipo correctamente.',
                201
            );
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateVetStaffRequest $request): JsonResponse
    {
        try {
            $guid    = $request->route('guid');
            $vet     = $request->attributes->get('current_vet');
            $profile = $this->userProfileService->findByGuidForVet($guid, $vet);

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

    public function show(Request $request): JsonResponse
    {
        try {
            $guid    = $request->route('guid');
            $vet     = $request->attributes->get('current_vet');
            $profile = $this->userProfileService->findByGuidForVet($guid, $vet);

            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            return $this->makeSuccess(
                new UserProfileResource($profile->load(['user', 'role', 'contacts']))
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function toggleBlock(Request $request): JsonResponse
    {
        try {
            $guid           = $request->route('guid');
            $vet            = $request->attributes->get('current_vet');
            $currentProfile = $request->attributes->get('current_profile');

            $profile = $this->userProfileService->findByGuidForVet($guid, $vet);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
            }

            if ($currentProfile && $profile->id === $currentProfile->id) {
                return $this->makeError(null, 'No podés bloquearte a vos mismo.', 403);
            }

            $profile = $this->userProfileService->toggleBlock($profile);

            $msg = $profile->blocked_at ? 'Acceso bloqueado para esta veterinaria.' : 'Acceso desbloqueado correctamente.';
            return $this->makeSuccess(new UserProfileResource($profile->load(['user', 'role', 'contacts'])), $msg);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
