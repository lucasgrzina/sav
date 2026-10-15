<?php

use App\Http\Controllers\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'admin'])->middleware('can:dashboard.admin.read');
});
