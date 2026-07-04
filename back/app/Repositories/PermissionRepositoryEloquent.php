<?php

namespace App\Repositories;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class PermissionRepositoryEloquent extends BaseRepositoryEloquent implements PermissionRepositoryInterface
{
    protected function model(): string
    {
        return Permission::class;
    }

    public function findManyByGuids(array $guids): Collection
    {
        return $this->newQuery()->whereIn('guid', $guids)->get();
    }
}
