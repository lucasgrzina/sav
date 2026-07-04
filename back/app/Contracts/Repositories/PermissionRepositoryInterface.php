<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;

interface PermissionRepositoryInterface
{
    public function findManyByGuids(array $guids): Collection;
}
