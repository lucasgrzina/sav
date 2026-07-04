<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserVetResource;
use App\Services\UserVetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserVetController extends Controller
{
    public function __construct(
        private UserVetService $userVetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $vets = $this->userVetService->getActiveVetsForUser($request->user());

            return $this->makeSuccess(UserVetResource::collection($vets));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
