<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VetHomeResource;
use App\Services\VetHomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetHomeController extends Controller
{
    public function __construct(private VetHomeService $homeService) {}

    public function show(Request $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $profile = $request->attributes->get('current_profile');

            return $this->makeSuccess(new VetHomeResource(
                $this->homeService->forProfile($vet, $profile, $request->user()->can('programs.alerts.read')),
            ));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
