<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemSettings\UpdateSystemSettingRequest;
use App\Http\Resources\V1\SystemSettingResource;
use App\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;

class SystemSettingController extends Controller
{
    public function __construct(
        private SystemSettingService $systemSettingService,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $settings = $this->systemSettingService->list();

            return $this->makeSuccess(SystemSettingResource::collection($settings));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $code): JsonResponse
    {
        try {
            $setting = $this->systemSettingService->findByCode($code);

            if (! $setting) {
                return $this->makeNotFound('Configuración no encontrada.');
            }

            return $this->makeSuccess(new SystemSettingResource($setting));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateSystemSettingRequest $request, string $code): JsonResponse
    {
        try {
            $setting = $this->systemSettingService->update($code, $request->validated()['value']);

            return $this->makeSuccess(new SystemSettingResource($setting), 'Configuración actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
