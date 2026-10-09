<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProvinceRepositoryInterface;
use App\Models\Province;
use Illuminate\Database\Eloquent\Collection;

class ProvinceRepositoryEloquent extends BaseRepositoryEloquent implements ProvinceRepositoryInterface
{
    protected function model(): string
    {
        return Province::class;
    }

    public function findByGuid(string $guid): ?Province
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByCountry(int $countryId): Collection
    {
        return $this->newQuery()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get();
    }
}
