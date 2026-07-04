<?php

use App\Http\Controllers\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/users')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::post('/tenant', [UserController::class, 'storeTenant']);
    Route::get('/{guid}', [UserController::class, 'show']);
    Route::put('/{guid}', [UserController::class, 'update']);
    Route::post('/{guid}/profiles', [UserController::class, 'storeProfile']);
    Route::patch('/{guid}/profiles/{profileGuid}/role', [UserController::class, 'changeProfileRole']);
    Route::delete('/{guid}', [UserController::class, 'destroy']);
    Route::patch('/{guid}/toggle-lock', [UserController::class, 'toggleLock']);
    Route::patch('/{guid}/change-password', [UserController::class, 'changePassword']);
    Route::post('/{guid}/reset-password', [UserController::class, 'resetPassword']);
});
