<?php

namespace App\Contracts\Repositories;

use App\Contracts\QueryCriterion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface UserRepositoryInterface
{
    public function findByGuid(string $guid): ?User;
    public function findByEmail(string $email): ?User;
    public function list(int $perPage, QueryCriterion ...$criteria): LengthAwarePaginator;
    public function create(array $data): User;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    public function toggleLock(User $user): User;
    public function updatePassword(User $user, string $password): User;
    public function exportQuery(QueryCriterion ...$criteria): Builder;
}
