<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(
        private PermissionService $permissionService,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $grouped = $this->permissionService->groupedByModule();

            return $this->makeSuccess($grouped);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
