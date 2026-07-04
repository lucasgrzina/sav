<?php

namespace App\Contracts\Repositories;

use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface VetRepositoryInterface
{
    public function findByGuid(string $guid): ?Vet;
    public function findBySlug(string $slug): ?Vet;
    public function create(array $data): Vet;
    public function update(Model $vet, array $data): Vet;
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
