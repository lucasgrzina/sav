<?php

namespace App\Contracts\Repositories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Collection;

interface ProvinceRepositoryInterface
{
    public function findByGuid(string $guid): ?Province;
    public function findByCountry(int $countryId): Collection;
}
