<?php

namespace App\Http\Controllers\V1;

use App\Contracts\Repositories\AnimalRepositoryInterface;
use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Animals\IndexAnimalRequest;
use App\Http\Resources\V1\AnimalListResource;
use Illuminate\Http\JsonResponse;

class AnimalController extends Controller
{
    public function __construct(
        private AnimalRepositoryInterface $animalRepository,
        private ClientRepositoryInterface $clientRepository,
    ) {}

    public function indexForClient(IndexAnimalRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientRepository->findByGuidForVet($request->route('client'), $vet);

            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $animals = $this->animalRepository->searchForClient($client->id, $request->query('search'));

            return $this->makeSuccess(AnimalListResource::collection($animals));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
