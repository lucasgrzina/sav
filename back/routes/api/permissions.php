<?php

use App\Http\Controllers\V1\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/permissions')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
});
