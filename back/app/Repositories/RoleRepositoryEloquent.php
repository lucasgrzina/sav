<?php

namespace App\Repositories;

use App\Contracts\QueryCriterion;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class RoleRepositoryEloquent extends BaseRepositoryEloquent implements RoleRepositoryInterface
{
    protected function model(): string
    {
        return Role::class;
    }

    public function findByGuid(string $guid): ?Role
    {
        return $this->newQuery()->with('permissions')->where('guid', $guid)->first();
    }

    public function findManyByGuids(array $guids): Collection
    {
        return $this->newQuery()->whereIn('guid', $guids)->get();
    }

    public function create(array $data): Role
    {
        return $this->model->newQuery()->create([
            'name'       => $data['name'],
            'guard_name' => 'web',
            'type'       => $data['type'] ?? Role::TYPE_PLATFORM,
        ]);
    }

    public function list(int $perPage, QueryCriterion ...$criteria): LengthAwarePaginator
    {
        return $this->buildQuery(...$criteria)
            ->with('permissions')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function update(Model $model, array $data): Role
    {
        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }

        if (! empty($updateData)) {
            $model->update($updateData);
        }

        return $model->fresh('permissions');
    }

    public function destroy(Model $model): bool|null
    {
        return $model->delete();
    }

    public function exportQuery(QueryCriterion ...$criteria): Builder
    {
        return $this->buildQuery(...$criteria)
            ->with('permissions')
            ->orderBy('name');
    }

    public function findByName(string $name): ?Role
    {
        return $this->newQuery()->where('name', $name)->first();
    }
}
