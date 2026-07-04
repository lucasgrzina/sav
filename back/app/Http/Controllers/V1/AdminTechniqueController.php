<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Exceptions\TechniqueChildHasProgramsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Techniques\CreateTechniqueRequest;
use App\Http\Requests\Techniques\IndexTechniqueRequest;
use App\Http\Requests\Techniques\UpdateTechniqueRequest;
use App\Http\Resources\V1\TechniqueListResource;
use App\Http\Resources\V1\TechniqueResource;
use App\Services\TechniqueService;
use Illuminate\Http\JsonResponse;

class AdminTechniqueController extends Controller
{
    public function __construct(
        private TechniqueService $techniqueService,
    ) {}

    public function index(IndexTechniqueRequest $request): JsonResponse
    {
        try {
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->techniqueService->paginateRoots($request->validated(), $perPage);

            return $this->makeSuccessPagination($paginator, TechniqueListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(CreateTechniqueRequest $request): JsonResponse
    {
        try {
            $technique = $this->techniqueService->create($request->validated());

            return $this->makeSuccess(new TechniqueResource($technique), 'Técnica creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);

            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }

            $detail = $this->techniqueService->getDetail($technique);

            return $this->makeSuccess([
                'technique' => new TechniqueResource($detail['technique']),
                'programs'  => $detail['programs'],
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateTechniqueRequest $request, string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);

            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }

            $technique = $this->techniqueService->update($technique, $request->validated());

            return $this->makeSuccess(new TechniqueResource($technique), 'Técnica actualizada correctamente.');
        } catch (TechniqueChildHasProgramsException $e) {
            return $this->makeError(
                ['conflicts' => $e->getConflicts()],
                $e->getMessage(),
                422,
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);

            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }

            $this->techniqueService->destroy($technique);

            return $this->makeSuccess(null, 'Técnica eliminada correctamente.');
        } catch (TechniqueCannotBeDeletedException $e) {
            return $this->makeError(
                ['reason' => $e->getReason(), 'count' => $e->getCount()],
                $e->getMessage(),
                422,
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
