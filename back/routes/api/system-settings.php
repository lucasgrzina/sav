<?php

use App\Http\Controllers\V1\SystemSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/system-settings')
    ->middleware(['auth:sanctum', 'can:system-settings.manage'])
    ->group(function () {
        Route::get('/', [SystemSettingController::class, 'index']);
        Route::get('/{code}', [SystemSettingController::class, 'show']);
        Route::patch('/{code}', [SystemSettingController::class, 'update']);
    });
