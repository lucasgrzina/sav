<?php

use App\Http\Controllers\V1\UserSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/user')->middleware(['auth:sanctum'])->group(function () {
    Route::get('settings',   [UserSettingController::class, 'index']);
    Route::patch('settings', [UserSettingController::class, 'upsert']);
});
