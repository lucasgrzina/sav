<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Establishments\GeocodeAddressRequest;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;

class GeocodingController extends Controller
{
    public function __construct(private GeocodingService $geocodingService) {}

    public function geocode(GeocodeAddressRequest $request): JsonResponse
    {
        try {
            $coordinates = $this->geocodingService->geocode($request->validated());

            return $this->makeSuccess($coordinates);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
