<?php

namespace App\Contracts\Repositories;

use App\Models\Program;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface ProgramRepositoryInterface
{
    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;

    public function findByGuidForVet(string $guid, int $vetId): ?Program;

    public function create(array $data): Program;

    public function update(Model $model, array $data): Model;
}
