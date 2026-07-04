<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CountryResource;
use App\Http\Resources\V1\DocumentTypeResource;
use App\Services\CountryService;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    public function __construct(
        private CountryService $countryService,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $countries = $this->countryService->list();

            return $this->makeSuccess(CountryResource::collection($countries));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function documentTypes(string $guid): JsonResponse
    {
        try {
            $country = $this->countryService->findByGuid($guid);

            if (!$country) {
                return $this->makeNotFound('País no encontrado.');
            }

            $types = $this->countryService->documentTypes($country);

            return $this->makeSuccess(DocumentTypeResource::collection($types));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
