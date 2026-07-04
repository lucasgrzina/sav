<?php

namespace App\Services;

use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

class CountryService
{
    public function __construct(
        private CountryRepositoryInterface      $countryRepository,
        private DocumentTypeRepositoryInterface $documentTypeRepository,
    ) {}

    public function list(): Collection
    {
        return $this->countryRepository->all();
    }

    public function findByGuid(string $guid): ?Country
    {
        return $this->countryRepository->findByGuid($guid);
    }

    public function documentTypes(Country $country): Collection
    {
        return $this->documentTypeRepository->findByCountry($country->id);
    }
}
