<?php

namespace App\Repositories;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepositoryEloquent
{
    protected Model $model;

    public function __construct()
    {
        $this->model = app($this->model());
    }

    abstract protected function model(): string;

    public function create(array $data): Model
    {
        return $this->model->newQuery()->create($data);
    }

    public function findByGuid(string $guid): ?Model
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function update(Model $model, array $data): Model
    {
        $model->fill($data);
        $model->save();

        return $model;
    }

    public function destroy(Model $model): bool|null
    {
        return $model->delete();
    }

    protected function newQuery(): Builder
    {
        return $this->model->newQuery();
    }

    protected function buildQuery(QueryCriterion ...$criteria): Builder
    {
        $query = $this->newQuery();

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query;
    }
}
