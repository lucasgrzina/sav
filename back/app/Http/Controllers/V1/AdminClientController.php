<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\IndexClientRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Requests\Admin\Clients\AdminLinkVetRequest;
use App\Http\Requests\Establishments\StoreEstablishmentRequest;
use App\Http\Requests\Establishments\UpdateEstablishmentRequest;
use App\Http\Requests\Members\Client\AdminAssignClientStaffRequest;
use App\Http\Requests\Members\Client\AdminChangeClientStaffRoleRequest;
use App\Http\Requests\Members\Client\UpdateClientStaffRequest;
use App\Http\Resources\V1\ClientResource;
use App\Http\Resources\V1\EstablishmentResource;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ClientService;
use App\Services\EstablishmentService;
use App\Services\UserProfileService;
use App\Services\VetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminClientController extends Controller
{
    public function __construct(
        private ClientService        $clientService,
        private VetService           $vetService,
        private UserProfileService   $userProfileService,
        private EstablishmentService $establishmentService,
    ) {}

    public function index(IndexClientRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $perPage = $request->integer('per_page', 15);

            $paginator = $this->clientService->paginateAll($filters, $perPage);

            return $this->makeSuccessPagination($paginator, ClientResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        try {
            $client = $this->clientService->createWithoutVet($request->validated());

            return $this->makeSuccess(new ClientResource($client), 'Cliente creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $client->load(['country', 'documentType', 'contacts', 'establishments', 'vets']);

            return $this->makeSuccess(new ClientResource($client));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateClientRequest $request, string $guid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $client = $this->clientService->update($client, $request->validated());

            return $this->makeSuccess(new ClientResource($client), 'Cliente actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Vincula una vet al cliente. Recibe { vet_guid } en el body.
     */
    public function linkVet(AdminLinkVetRequest $request, string $clientGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($clientGuid);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $vet = $this->vetService->findByGuid($request->validated()['vet_guid']);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $this->clientService->linkToVet($client, $vet);

            return $this->makeSuccess(null, 'Veterinaria vinculada correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Desvincula una vet del cliente.
     */
    public function unlinkVet(string $clientGuid, string $vetGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($clientGuid);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $vet = $this->vetService->findByGuid($vetGuid);

            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $this->clientService->detach($client, $vet);

            return $this->makeSuccess(null, 'Veterinaria desvinculada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffIndex(string $guid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $staff = $this->userProfileService->listStaffForClient($client);

            return $this->makeSuccess(UserProfileResource::collection($staff));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffStore(AdminAssignClientStaffRequest $request, string $guid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
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

            return $this->makeSuccess(new UserProfileResource($profile), 'Miembro agregado correctamente.', 201);
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffShow(string $guid, string $profileGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->findByGuidForClient($profileGuid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
            }

            $profile->load(['user', 'role', 'contacts']);

            return $this->makeSuccess(new UserProfileResource($profile));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function staffUpdate(UpdateClientStaffRequest $request, string $guid, string $profileGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->findByGuidForClient($profileGuid, $client);
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

    public function staffChangeRole(AdminChangeClientStaffRoleRequest $request, string $guid, string $profileGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->findByGuidForClient($profileGuid, $client);
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

    public function staffDestroy(string $guid, string $profileGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->findByGuidForClient($profileGuid, $client);
            if (!$profile) {
                return $this->makeNotFound('Miembro no encontrado en este cliente.');
            }

            $this->userProfileService->removeMember($profile);

            return $this->makeSuccess(null, 'Miembro eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function establishmentIndex(string $guid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishments = $this->establishmentService->listForClient($client);

            return $this->makeSuccess(EstablishmentResource::collection($establishments));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function establishmentStore(StoreEstablishmentRequest $request, string $guid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->create($client, $request->validated());

            return $this->makeSuccess(
                new EstablishmentResource($establishment),
                'Establecimiento creado correctamente.',
                201,
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function establishmentUpdate(UpdateEstablishmentRequest $request, string $guid, string $estGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->findByGuidForClient($estGuid, $client);
            if (!$establishment) {
                return $this->makeNotFound('Establecimiento no encontrado.');
            }

            $establishment = $this->establishmentService->update($establishment, $request->validated());

            return $this->makeSuccess(
                new EstablishmentResource($establishment),
                'Establecimiento actualizado correctamente.',
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function establishmentDestroy(string $guid, string $estGuid): JsonResponse
    {
        try {
            $client = $this->clientService->findByGuid($guid);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->findByGuidForClient($estGuid, $client);
            if (!$establishment) {
                return $this->makeNotFound('Establecimiento no encontrado.');
            }

            $this->establishmentService->destroy($establishment);

            return $this->makeSuccess(null, 'Establecimiento eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Busca un client por tax_id y verifica vinculación con la vet indicada.
     * Endpoint: GET /v1/admin/clients/lookup?tax_id=X&vet_guid=Y
     * Sin scope de vet (contexto admin).
     */
    public function lookup(Request $request): JsonResponse
    {
        try {
            $taxId   = $request->query('tax_id');
            $vetGuid = $request->query('vet_guid');

            if (empty($taxId)) {
                return $this->makeError(
                    ['tax_id' => ['El parámetro tax_id es requerido.']],
                    'Parámetros inválidos.',
                    422,
                );
            }

            if (empty($vetGuid)) {
                return $this->makeError(
                    ['vet_guid' => ['El parámetro vet_guid es requerido.']],
                    'Parámetros inválidos.',
                    422,
                );
            }

            // Validar existencia de la vet antes de buscar
            $vet = $this->vetService->findByGuid($vetGuid);
            if (!$vet) {
                return $this->makeNotFound('Veterinaria no encontrada.');
            }

            $result = $this->clientService->lookupForVet($taxId, $vetGuid);

            if (!$result['found']) {
                return $this->makeSuccess(['found' => false, 'client' => null, 'already_linked' => false]);
            }

            $result['client']->load(['country', 'documentType']);

            return $this->makeSuccess([
                'found'          => true,
                'already_linked' => $result['already_linked'],
                'client'         => new ClientResource($result['client']),
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
