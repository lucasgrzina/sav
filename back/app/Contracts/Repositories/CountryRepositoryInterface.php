<?php

namespace App\Contracts\Repositories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

interface CountryRepositoryInterface
{
    public function all(): Collection;
    public function findByGuid(string $guid): ?Country;
}
