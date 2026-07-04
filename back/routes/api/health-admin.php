<?php

use App\Http\Controllers\V1\AdminHealthActivityController;
use App\Http\Controllers\V1\AdminHealthPlanCategoryController;
use App\Http\Controllers\V1\AdminHealthPlanTemplateController;
use Illuminate\Support\Facades\Route;

// Health Activities
Route::prefix('v1/admin/health-activities')->middleware('auth:sanctum')->group(function () {
    Route::get('/',          [AdminHealthActivityController::class, 'index'])->middleware('can:health-activities.read');
    Route::post('/',         [AdminHealthActivityController::class, 'store'])->middleware('can:health-activities.create');
    Route::put('/{guid}',    [AdminHealthActivityController::class, 'update'])->middleware('can:health-activities.update');
    Route::delete('/{guid}', [AdminHealthActivityController::class, 'destroy'])->middleware('can:health-activities.delete');
});

// Health Plan Categories
Route::prefix('v1/admin/health-plan-categories')->middleware('auth:sanctum')->group(function () {
    Route::get('/',          [AdminHealthPlanCategoryController::class, 'index'])->middleware('can:health-plan-categories.read');
    Route::post('/',         [AdminHealthPlanCategoryController::class, 'store'])->middleware('can:health-plan-categories.create');
    Route::put('/{guid}',    [AdminHealthPlanCategoryController::class, 'update'])->middleware('can:health-plan-categories.update');
    Route::delete('/{guid}', [AdminHealthPlanCategoryController::class, 'destroy'])->middleware('can:health-plan-categories.delete');
});

// Health Plan Templates
Route::prefix('v1/admin/health-plan-templates')->middleware('auth:sanctum')->group(function () {
    Route::get('/',          [AdminHealthPlanTemplateController::class, 'index'])->middleware('can:health-plan-templates.read');
    Route::post('/',         [AdminHealthPlanTemplateController::class, 'store'])->middleware('can:health-plan-templates.create');
    Route::get('/{guid}',    [AdminHealthPlanTemplateController::class, 'show'])->middleware('can:health-plan-templates.read');
    Route::put('/{guid}',    [AdminHealthPlanTemplateController::class, 'update'])->middleware('can:health-plan-templates.update');
    Route::delete('/{guid}', [AdminHealthPlanTemplateController::class, 'destroy'])->middleware('can:health-plan-templates.delete');
});
