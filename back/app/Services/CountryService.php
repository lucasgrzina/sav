<?php

namespace App\Services;

use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Contracts\Repositories\ProvinceRepositoryInterface;
use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

class CountryService
{
    public function __construct(
        private CountryRepositoryInterface      $countryRepository,
        private DocumentTypeRepositoryInterface $documentTypeRepository,
        private ProvinceRepositoryInterface     $provinceRepository,
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

    public function provinces(Country $country): Collection
    {
        return $this->provinceRepository->findByCountry($country->id);
    }
}
