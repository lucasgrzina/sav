<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Establishments\StoreEstablishmentRequest;
use App\Http\Requests\Establishments\UpdateEstablishmentRequest;
use App\Http\Resources\V1\EstablishmentResource;
use App\Services\ClientService;
use App\Services\EstablishmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentController extends Controller
{
    public function __construct(
        private ClientService        $clientService,
        private EstablishmentService $establishmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $vet        = $request->attributes->get('current_vet');
            $clientGuid = $request->route('client');
            $client     = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishments = $this->establishmentService->listForClient($client);

            return $this->makeSuccess(EstablishmentResource::collection($establishments));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreEstablishmentRequest $request): JsonResponse
    {
        try {
            $vet        = $request->attributes->get('current_vet');
            $clientGuid = $request->route('client');
            $client     = $this->clientService->findByGuidForVet($clientGuid, $vet);

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

    public function update(UpdateEstablishmentRequest $request): JsonResponse
    {
        try {
            $vet        = $request->attributes->get('current_vet');
            $clientGuid = $request->route('client');
            $guid       = $request->route('guid');
            $client     = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->findByGuidForClient($guid, $client);

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

    public function destroy(Request $request): JsonResponse
    {
        try {
            $vet        = $request->attributes->get('current_vet');
            $clientGuid = $request->route('client');
            $guid       = $request->route('guid');
            $client     = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $establishment = $this->establishmentService->findByGuidForClient($guid, $client);

            if (!$establishment) {
                return $this->makeNotFound('Establecimiento no encontrado.');
            }

            $this->establishmentService->destroy($establishment);

            return $this->makeSuccess(null, 'Establecimiento eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
