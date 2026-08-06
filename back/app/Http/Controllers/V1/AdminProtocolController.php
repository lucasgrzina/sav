<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\ProtocolHasProgramsException;
use App\Exceptions\ProtocolTechniqueLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Protocols\IndexProtocolRequest;
use App\Http\Requests\Protocols\SimulateProtocolRequest;
use App\Http\Requests\Protocols\StoreProtocolRequest;
use App\Http\Requests\Protocols\UpdateProtocolRequest;
use App\Http\Resources\V1\ProtocolListResource;
use App\Http\Resources\V1\ProtocolResource;
use App\Http\Resources\V1\ProtocolSimulationResource;
use App\Models\Country;
use App\Models\Technique;
use App\Services\ProtocolService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminProtocolController extends Controller
{
    public function __construct(private ProtocolService $protocolService) {}

    public function index(IndexProtocolRequest $request): JsonResponse
    {
        try {
            $filters = [
                'technique_guid' => $request->query('technique_id'),
                'country_guid'   => $request->has('country_id') ? $request->query('country_id') : false,
                'search'         => $request->query('search'),
            ];
            // 'country_guid' => false significa "sin filtro"; null explícito significa "solo globales"
            if ($filters['country_guid'] === false) {
                unset($filters['country_guid']);
            }

            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->protocolService->paginateByRootTechnique($request->query('root_guid'), $filters, $perPage);

            return $this->makeSuccessPagination($paginator, ProtocolListResource::class);
        } catch (ModelNotFoundException $e) {
            return $this->makeNotFound('Técnica raíz no encontrada.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreProtocolRequest $request): JsonResponse
    {
        try {
            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->create($data, $request->user()->id);

            return $this->makeSuccess(new ProtocolResource($protocol), 'Protocolo creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            return $this->makeSuccess(new ProtocolResource($protocol));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateProtocolRequest $request, string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->update($protocol, $data);

            return $this->makeSuccess(new ProtocolResource($protocol), 'Protocolo actualizado correctamente.');
        } catch (ProtocolTechniqueLockedException $e) {
            return $this->makeError(['reason' => 'technique_locked', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** Cálculo de cronograma en memoria, sin persistir nada (DEC-NEG-01). */
    public function simulate(SimulateProtocolRequest $request, string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $baseDate = Carbon::createFromFormat('Y-m-d', $request->validated('base_date'))->startOfDay();
            $result   = $this->protocolService->simulate($protocol, $baseDate);

            return $this->makeSuccess(new ProtocolSimulationResource($result));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function replicate(Request $request, string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $clone = $this->protocolService->replicate($protocol, $request->user()->id);

            return $this->makeSuccess(new ProtocolResource($clone), 'Protocolo replicado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
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

    /** Traduce technique_id/country_id (guid) → id interno antes de pasar al Service. */
    private function resolveGuidsToIds(array $data): array
    {
        $data['technique_id'] = Technique::where('guid', $data['technique_id'])->value('id');
        $data['country_id']   = $data['country_id'] ? Country::where('guid', $data['country_id'])->value('id') : null;
        return $data;
    }
}
