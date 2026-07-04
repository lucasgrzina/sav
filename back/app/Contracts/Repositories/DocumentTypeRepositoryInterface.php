<?php

namespace App\Contracts\Repositories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;

interface DocumentTypeRepositoryInterface
{
    public function all(): Collection;
    public function findByGuid(string $guid): ?DocumentType;
    public function findByCountry(int $countryId): Collection;
}
