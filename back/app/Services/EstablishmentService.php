<?php

namespace App\Services;

use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Models\Client;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Collection;

class EstablishmentService
{
    public function __construct(
        private EstablishmentRepositoryInterface $establishmentRepository,
    ) {}

    public function listForClient(Client $client): Collection
    {
        return $this->establishmentRepository->listForClient($client);
    }

    public function create(Client $client, array $data): Establishment
    {
        $data['client_id'] = $client->id;
        return $this->establishmentRepository->create($data);
    }

    public function findByGuidForClient(string $guid, Client $client): ?Establishment
    {
        return $this->establishmentRepository->findByGuidForClient($guid, $client);
    }

    public function update(Establishment $establishment, array $data): Establishment
    {
        return $this->establishmentRepository->update($establishment, $data);
    }

    public function destroy(Establishment $establishment): void
    {
        $this->establishmentRepository->destroy($establishment);
    }
}
