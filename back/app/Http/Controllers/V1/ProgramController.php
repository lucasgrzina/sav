<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\ProgramMustHaveOneTargetException;
use App\Exceptions\ProgramNotEditableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\IndexProgramRequest;
use App\Http\Requests\Programs\StoreProgramRequest;
use App\Http\Requests\Programs\UpdateProgramRequest;
use App\Http\Resources\V1\ProgramListResource;
use App\Http\Resources\V1\ProgramResource;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Protocol;
use App\Models\UserProfile;
use App\Services\ProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function __construct(private ProgramService $programService) {}

    public function index(IndexProgramRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $filters = [
                'technique_guid'     => $request->query('technique_id'),
                'client_guid'        => $request->query('client_id'),
                'establishment_guid' => $request->query('establishment_id'),
                'cancelled'          => $request->has('cancelled') ? $request->boolean('cancelled') : null,
                'search'             => $request->query('search'),
            ];
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->programService->paginateForVet($vet->id, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, ProgramListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $data = $this->resolveGuidsToIds($request->validated());
            $program = $this->programService->create($data, $vet->id);

            return $this->makeSuccess(new ProgramResource($program), 'Programa creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $program = $this->programService->findByGuidForVet($guid, $vet->id);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            return $this->makeSuccess(new ProgramResource($program));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateProgramRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $program = $this->programService->findByGuidForVet($guid, $vet->id);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $data = $this->resolveGuidsToIds($request->validated());
            $program = $this->programService->update($program, $data);

            return $this->makeSuccess(new ProgramResource($program), 'Programa actualizado correctamente.');
        } catch (ProgramNotEditableException $e) {
            return $this->makeError(['reason' => 'not_editable'], $e->getMessage(), 422);
        } catch (ProgramMustHaveOneTargetException $e) {
            return $this->makeError(['reason' => 'must_have_one_target'], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $program = $this->programService->findByGuidForVet($guid, $vet->id);

            if (!$program) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $program = $this->programService->cancel($program);

            return $this->makeSuccess(new ProgramResource($program), 'Programa cancelado correctamente.');
        } catch (ProgramNotEditableException $e) {
            return $this->makeError(['reason' => 'not_editable'], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Resuelve guids a ids internos. targets.*.animals.*.id se deja como guid sin resolver:
     * ProgramService::syncTargetAnimals lo resuelve vía AnimalRepositoryInterface::findByGuid
     * y descarta silenciosamente si no pertenece al client_id del programa (DEC-07, regla 2).
     */
    private function resolveGuidsToIds(array $data): array
    {
        $data['client_id']        = Client::where('guid', $data['client_id'])->value('id');
        $data['establishment_id'] = Establishment::where('guid', $data['establishment_id'])->value('id');
        $data['protocol_id']      = Protocol::where('guid', $data['protocol_id'])->value('id');

        $data['manager_profile_ids'] = collect($data['manager_profile_ids'])
            ->map(fn ($guid) => UserProfile::where('guid', $guid)->value('id'))
            ->filter()
            ->values()
            ->all();

        return $data;
    }
}
