<?php

namespace App\Repositories;

use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

class CountryRepositoryEloquent extends BaseRepositoryEloquent implements CountryRepositoryInterface
{
    protected function model(): string
    {
        return Country::class;
    }

    public function all(): Collection
    {
        return $this->newQuery()->orderBy('name')->get();
    }

    public function findByGuid(string $guid): ?Country
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }
}
