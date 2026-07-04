<?php

use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('roles', RoleController::class)->parameters(['roles' => 'guid']);

    Route::put('users/{guid}/roles', [UserRoleController::class, 'sync']);
});
