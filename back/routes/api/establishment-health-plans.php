<?php

use App\Http\Controllers\V1\EstablishmentHealthPlanController;
use App\Http\Controllers\V1\VetHealthActivityController;
use App\Http\Controllers\V1\VetHealthPlanCategoryController;
use App\Http\Controllers\V1\VetHealthPlanTemplateController;
use Illuminate\Support\Facades\Route;

// Panel Tenant Vet — instanciar plan sanitario sobre un establecimiento + confirmación manual
Route::prefix('v1/vets/{vet}/establishment-health-plans')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [EstablishmentHealthPlanController::class, 'index'])->middleware('can:establishment-health-plans.read');
    Route::post('/', [EstablishmentHealthPlanController::class, 'store'])->middleware('can:establishment-health-plans.create');
    Route::get('/{guid}', [EstablishmentHealthPlanController::class, 'show'])->middleware('can:establishment-health-plans.read');
    Route::post('/{guid}/cancel', [EstablishmentHealthPlanController::class, 'cancel'])->middleware('can:establishment-health-plans.update');
    Route::post('/{guid}/activities/{activityGuid}/confirm', [EstablishmentHealthPlanController::class, 'confirmActivity'])->middleware('can:establishment-health-plans.confirm');
});

// RF-01 — catálogo de solo lectura para el panel tenant (DEC-07: reutiliza el permiso .read)
// TKT-008 — CRUD de plantillas propias del vet, gateado por permisos dedicados .templates.*
Route::prefix('v1/vets/{vet}/health-plan-templates')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetHealthPlanTemplateController::class, 'index'])->middleware('can:establishment-health-plans.read');
    Route::post('/', [VetHealthPlanTemplateController::class, 'store'])->middleware('can:establishment-health-plans.templates.create');
    Route::get('/{guid}', [VetHealthPlanTemplateController::class, 'show'])->middleware('can:establishment-health-plans.read');
    Route::put('/{guid}', [VetHealthPlanTemplateController::class, 'update'])->middleware('can:establishment-health-plans.templates.update');
    Route::delete('/{guid}', [VetHealthPlanTemplateController::class, 'destroy'])->middleware('can:establishment-health-plans.templates.delete');
});

// Gap TKT-008 — catálogo global de solo lectura para que el vet arme sus plantillas propias
// (DEC-NEG-03: no crea/edita HealthActivity ni HealthPlanCategory, solo las lista).
// Reutiliza el mismo permiso `.read` que el resto del namespace tenant, en vez de dar acceso
// a los permisos `health-activities.*`/`health-plan-categories.*` reservados a super-admin.
Route::prefix('v1/vets/{vet}/health-activities')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetHealthActivityController::class, 'index'])->middleware('can:establishment-health-plans.read');
});

Route::prefix('v1/vets/{vet}/health-plan-categories')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetHealthPlanCategoryController::class, 'index'])->middleware('can:establishment-health-plans.read');
});
