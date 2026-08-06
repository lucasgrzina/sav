<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\ProtocolHasProgramsException;
use App\Exceptions\ProtocolTechniqueLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Protocols\IndexVetProtocolRequest;
use App\Http\Requests\Protocols\StoreVetProtocolRequest;
use App\Http\Requests\Protocols\UpdateVetProtocolRequest;
use App\Http\Resources\V1\VetProtocolListResource;
use App\Http\Resources\V1\VetProtocolResource;
use App\Models\Technique;
use App\Services\ProtocolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetProtocolController extends Controller
{
    public function __construct(private ProtocolService $protocolService) {}

    public function index(IndexVetProtocolRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $filters = [
                'technique_guid' => $request->query('technique_id'),
                'search'         => $request->query('search'),
            ];
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->protocolService->paginateForVet($vet->id, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, VetProtocolListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreVetProtocolRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->create($data, $request->user()->id, $vet->id);

            return $this->makeSuccess(new VetProtocolResource($protocol), 'Protocolo creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** Precarga: show funciona sobre CUALQUIER protocolo visible (global o propio). */
    public function show(Request $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $protocol = $this->protocolService->findByGuidWithTasks($guid);

            if (!$protocol || ($protocol->vet_id !== null && $protocol->vet_id !== $vet->id)) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            return $this->makeSuccess(new VetProtocolResource($protocol));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateVetProtocolRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $protocol = $this->protocolService->findByGuidForVet($guid, $vet->id);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->update($protocol, $data);

            return $this->makeSuccess(new VetProtocolResource($protocol), 'Protocolo actualizado correctamente.');
        } catch (ProtocolTechniqueLockedException $e) {
            return $this->makeError(['reason' => 'technique_locked', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $protocol = $this->protocolService->findByGuidForVet($guid, $vet->id);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $this->protocolService->destroy($protocol);

            return $this->makeSuccess(null, 'Protocolo eliminado correctamente.');
        } catch (ProtocolHasProgramsException $e) {
            return $this->makeError(['reason' => 'has_programs', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    private function resolveGuidsToIds(array $data): array
    {
        $data['technique_id'] = Technique::where('guid', $data['technique_id'])->value('id');
        unset($data['country_id']); // DEC-07: nunca se persiste desde el panel vet
        return $data;
    }
}
