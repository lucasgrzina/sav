<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\Client\AssignClientStaffRequest;
use App\Http\Requests\Members\Client\ChangeClientStaffRoleRequest;
use App\Http\Requests\Members\Client\CreateClientStaffRequest;
use App\Http\Requests\Members\Client\LookupClientStaffRequest;
use App\Http\Requests\Members\Client\UpdateClientStaffRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Models\Client;
use App\Services\ClientService;
use App\Services\ContactService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientStaffController extends Controller
{
    public function __construct(
        private UserProfileService $userProfileService,
        private ClientService      $clientService,
        private ContactService     $contactService,
    ) {}

    /**
     * Resuelve el Client por guid verificando que pertenece a la vet del tenant autenticado.
     * Retorna null si no existe o si no está asociado a la vet activa.
     */
    private function resolveClient(Request $request): ?Client
    {
        $vet        = $request->attributes->get('current_vet');
        $clientGuid = $request->route('client');

        return $this->clientService->findByGuidForVet($clientGuid, $vet);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $members = $this->userProfileService->listForClient($client);

            return $this->makeSuccess(UserProfileResource::collection($members));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(AssignClientStaffRequest $request): JsonResponse
    {
        try {
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
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

            $profile = $this->userProfileService->addMemberToClient($client, $user, $role);

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
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $guid           = $request->route('guid');
            $currentProfile = $request->attributes->get('current_profile');

            $profile = $this->userProfileService->findByGuidForClient($guid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
            }

            if ($currentProfile && $profile->id === $currentProfile->id) {
                return $this->makeError(null, 'No podés eliminarte a vos mismo del cliente.', 403);
            }

            $this->userProfileService->removeMember($profile);

            return $this->makeSuccess(null, 'Miembro eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function changeRole(ChangeClientStaffRoleRequest $request): JsonResponse
    {
        try {
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $guid    = $request->route('guid');
            $profile = $this->userProfileService->findByGuidForClient($guid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
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

    public function lookup(LookupClientStaffRequest $request): JsonResponse
    {
        try {
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $result = $this->userProfileService->lookupForClient(
                $request->validated()['email'],
                $client
            );

            return $this->makeSuccess($result);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function createAndAssign(CreateClientStaffRequest $request): JsonResponse
    {
        try {
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->createAndAssignClientStaff(
                $client,
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

    public function update(UpdateClientStaffRequest $request): JsonResponse
    {
        try {
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $guid    = $request->route('guid');
            $profile = $this->userProfileService->findByGuidForClient($guid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
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
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $guid    = $request->route('guid');
            $profile = $this->userProfileService->findByGuidForClient($guid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
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
            $client = $this->resolveClient($request);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $guid           = $request->route('guid');
            $currentProfile = $request->attributes->get('current_profile');

            $profile = $this->userProfileService->findByGuidForClient($guid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
            }

            if ($currentProfile && $profile->id === $currentProfile->id) {
                return $this->makeError(null, 'No podés bloquearte a vos mismo.', 403);
            }

            $profile = $this->userProfileService->toggleBlock($profile);

            $msg = $profile->blocked_at
                ? 'Acceso bloqueado para este cliente.'
                : 'Acceso desbloqueado correctamente.';

            return $this->makeSuccess(
                new UserProfileResource($profile->load(['user', 'role', 'contacts'])),
                $msg
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
