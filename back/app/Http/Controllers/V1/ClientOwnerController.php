<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreOwnerRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ClientService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientOwnerController extends Controller
{
    public function __construct(
        private ClientService      $clientService,
        private UserProfileService $userProfileService,
    ) {}

    /**
     * Lista los UserProfiles con role client-owner del Client dado.
     * Verifica primero que el client pertenece al tenant actual.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $guid   = $request->route('guid');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $owners = $this->userProfileService->listOwnersForClient($client);

            return $this->makeSuccess(UserProfileResource::collection($owners));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Crea un client-owner para el Client dado.
     * Si el User no existe, lo crea y encola el job de invitación.
     * Verifica que el client pertenece al tenant actual antes de operar.
     */
    public function store(StoreOwnerRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $guid   = $request->route('guid');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $profile = $this->userProfileService->addOwnerToClient(
                $client,
                $request->validated(),
            );

            $profile->load(['user', 'role']);

            return $this->makeSuccess(
                new UserProfileResource($profile),
                'Owner creado correctamente.',
                201,
            );
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
