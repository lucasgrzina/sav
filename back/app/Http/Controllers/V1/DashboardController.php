<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DashboardAdminResource;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private DashboardStatsService $dashboardStatsService) {}

    public function admin(): JsonResponse
    {
        try {
            return $this->makeSuccess(new DashboardAdminResource($this->dashboardStatsService->forAdmin()));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
