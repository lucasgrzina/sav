<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vets\UpdateVetRequest;
use App\Http\Resources\V1\VetResource;
use App\Services\VetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetController extends Controller
{
    public function __construct(
        private VetService $vetService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');

            $vet->load(['country', 'documentType', 'contacts']);

            return $this->makeSuccess(new VetResource($vet));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateVetRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');

            $vet = $this->vetService->update($vet, $request->validated());

            return $this->makeSuccess(new VetResource($vet), 'Datos actualizados correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
