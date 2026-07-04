<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\IndexClientRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\StoreLinkRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\V1\ClientResource;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService,
    ) {}

    public function index(IndexClientRequest $request): JsonResponse
    {
        try {
            $vet     = $request->attributes->get('current_vet');
            $filters = $request->validated();
            $perPage = $request->integer('per_page', 15);

            $paginator = $this->clientService->paginate($vet, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, ClientResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientService->create($vet, $request->validated());

            $client->load(['country', 'documentType', 'contacts']);

            return $this->makeSuccess(new ClientResource($client), 'Cliente creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $guid   = $request->route('guid');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $client->load(['country', 'documentType', 'contacts', 'establishments']);

            return $this->makeSuccess(new ClientResource($client));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateClientRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $guid   = $request->route('guid');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $client = $this->clientService->update($client, $request->validated());

            return $this->makeSuccess(new ClientResource($client), 'Cliente actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $guid   = $request->route('guid');
            $client = $this->clientService->findByGuidForVet($guid, $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $this->clientService->detach($client, $vet);

            return $this->makeSuccess(null, 'Cliente desvinculado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Busca un client globalmente por tax_id.
     * No requiere que el client esté vinculado al tenant actual.
     * Retorna found=true/false + datos del client si existe + already_linked.
     */
    public function lookup(Request $request): JsonResponse
    {
        try {
            $taxId = $request->query('tax_id');

            if (empty($taxId)) {
                return $this->makeError(
                    ['tax_id' => ['El parámetro tax_id es requerido.']],
                    'Parámetros inválidos.',
                    422,
                );
            }

            $vet    = $request->attributes->get('current_vet');
            $result = $this->clientService->lookupByTaxId($taxId, $vet);

            if (!$result['found']) {
                return $this->makeSuccess(['found' => false, 'client' => null]);
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

    /**
     * Vincula un client existente (identificado por guid en ruta) al tenant actual.
     * Retorna 422 si ya está vinculado.
     */
    public function link(StoreLinkRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $guid   = $request->route('guid');
            $client = $this->clientService->findByGuid($guid);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $this->clientService->linkToVet($client, $vet);

            $client->load(['country', 'documentType']);

            return $this->makeSuccess(
                new ClientResource($client),
                'Cliente vinculado correctamente.',
                201,
            );
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
