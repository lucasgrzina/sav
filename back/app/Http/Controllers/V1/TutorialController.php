<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tutorials\StoreTutorialRequest;
use App\Http\Requests\Tutorials\UpdateTutorialRequest;
use App\Http\Resources\V1\TutorialResource;
use App\Services\TutorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutorialController extends Controller
{
    public function __construct(
        private TutorialService $tutorialService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            /*if (! $request->user()->can('tutorials.read')) {
                return $this->makeError(null, 'No tenés permiso para ver los tutoriales.', 403);
            }*/

            $tutorials = $this->tutorialService->list();

            return $this->makeSuccess(TutorialResource::collection($tutorials));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreTutorialRequest $request): JsonResponse
    {
        try {
            if (! $request->user()->can('tutorials.create')) {
                return $this->makeError(null, 'No tenés permiso para crear tutoriales.', 403);
            }

            $tutorial = $this->tutorialService->store($request->validated());

            return $this->makeSuccess(new TutorialResource($tutorial), 'Tutorial creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateTutorialRequest $request, string $guid): JsonResponse
    {
        try {
            if (! $request->user()->can('tutorials.update')) {
                return $this->makeError(null, 'No tenés permiso para modificar tutoriales.', 403);
            }

            $tutorial = $this->tutorialService->findByGuid($guid);

            if (! $tutorial) {
                return $this->makeNotFound('Tutorial no encontrado.');
            }

            $tutorial = $this->tutorialService->update($tutorial, $request->validated());

            return $this->makeSuccess(new TutorialResource($tutorial), 'Tutorial actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request, string $guid): JsonResponse
    {
        try {
            if (! $request->user()->can('tutorials.delete')) {
                return $this->makeError(null, 'No tenés permiso para eliminar tutoriales.', 403);
            }

            $tutorial = $this->tutorialService->findByGuid($guid);

            if (! $tutorial) {
                return $this->makeNotFound('Tutorial no encontrado.');
            }

            $this->tutorialService->destroy($tutorial);

            return $this->makeSuccess(null, 'Tutorial eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
