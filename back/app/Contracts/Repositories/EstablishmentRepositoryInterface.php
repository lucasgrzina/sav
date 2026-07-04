<?php

namespace App\Contracts\Repositories;

use App\Models\Client;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface EstablishmentRepositoryInterface
{
    public function findByGuidForClient(string $guid, Client $client): ?Establishment;

    public function listForClient(Client $client): Collection;

    public function create(array $data): Establishment;

    public function update(Model $establishment, array $data): Establishment;

    public function destroy(Model $establishment): bool|null;
}
