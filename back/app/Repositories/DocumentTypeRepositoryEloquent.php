<?php

namespace App\Repositories;

use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;

class DocumentTypeRepositoryEloquent extends BaseRepositoryEloquent implements DocumentTypeRepositoryInterface
{
    protected function model(): string
    {
        return DocumentType::class;
    }

    public function all(): Collection
    {
        return $this->newQuery()->with('country')->orderBy('name')->get();
    }

    public function findByGuid(string $guid): ?DocumentType
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
