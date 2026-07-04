<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserSettingRequest;
use App\Http\Resources\V1\UserSettingResource;
use App\Services\UserSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingController extends Controller
{
    public function __construct(private UserSettingService $userSettingService) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $settings = $this->userSettingService->getAll($request->user());
            return $this->makeSuccess(new UserSettingResource($settings));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function upsert(UpdateUserSettingRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->userSettingService->set(
                $request->user(),
                $validated['code'],
                $validated['value'],
            );
            $settings = $this->userSettingService->getAll($request->user());
            return $this->makeSuccess(new UserSettingResource($settings));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
