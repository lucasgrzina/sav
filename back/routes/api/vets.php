<?php

use App\Http\Controllers\V1\AdminVetController;
use App\Http\Controllers\V1\ContactController;
use App\Http\Controllers\V1\VetController;
use App\Http\Controllers\V1\VetStaffController;
use Illuminate\Support\Facades\Route;

// --- Panel SuperAdmin ---
Route::prefix('v1/admin/vets')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AdminVetController::class, 'index'])->middleware('can:vets.read');
    Route::post('/', [AdminVetController::class, 'store'])->middleware('can:vets.create');
    Route::get('/{guid}', [AdminVetController::class, 'show'])->middleware('can:vets.read');
    Route::put('/{guid}', [AdminVetController::class, 'update'])->middleware('can:vets.update');
    Route::patch('/{guid}/validate', [AdminVetController::class, 'validate'])->middleware('can:vets.validate');
    Route::patch('/{guid}/suspend', [AdminVetController::class, 'suspend'])->middleware('can:vets.validate');
    Route::patch('/{guid}/unsuspend', [AdminVetController::class, 'unsuspend'])->middleware('can:vets.validate');
    Route::get('/{guid}/clients', [AdminVetController::class, 'clients'])->middleware('can:clients.read');

    // Staff de vet (panel admin)
    Route::get('/{guid}/staff',                                          [AdminVetController::class, 'staffIndex'])         ->middleware('can:vets.staff.read');
    Route::post('/{guid}/staff',                                         [AdminVetController::class, 'staffStore'])         ->middleware('can:vets.staff.create');
    Route::get('/{guid}/staff/{profileGuid}',        [AdminVetController::class, 'staffShow'])      ->middleware('can:vets.staff.read');
    Route::put('/{guid}/staff/{profileGuid}',        [AdminVetController::class, 'staffUpdate'])    ->middleware('can:vets.staff.update');
    Route::patch('/{guid}/staff/{profileGuid}/role', [AdminVetController::class, 'staffChangeRole'])->middleware('can:vets.staff.update');
    Route::delete('/{guid}/staff/{profileGuid}',     [AdminVetController::class, 'staffDestroy'])   ->middleware('can:vets.staff.delete');
});

// --- Panel Tenant ---
Route::prefix('v1/vets/{vet}')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetController::class, 'show']);
    Route::put('/', [VetController::class, 'update']);

    // Staff
    Route::prefix('staff')->group(function () {
        Route::get('/', [VetStaffController::class, 'index']);
        Route::post('/', [VetStaffController::class, 'store']);
        // Rutas estáticas ANTES de las rutas con parámetros dinámicos
        Route::get('/lookup', [VetStaffController::class, 'lookup']);
        Route::post('/new-user', [VetStaffController::class, 'createAndAssign']);
        // Rutas dinámicas al final
        Route::get('/{guid}', [VetStaffController::class, 'show']);
        Route::put('/{guid}', [VetStaffController::class, 'update']);
        Route::delete('/{guid}', [VetStaffController::class, 'destroy']);
        Route::patch('/{guid}/role', [VetStaffController::class, 'changeRole']);
        Route::patch('/{guid}/toggle-block', [VetStaffController::class, 'toggleBlock']);

        // Contactos de un miembro del staff
        Route::prefix('/{profile}/contacts')->group(function () {
            Route::get('/', [ContactController::class, 'index']);
            Route::post('/', [ContactController::class, 'store']);
            Route::put('/{guid}', [ContactController::class, 'update']);
            Route::delete('/{guid}', [ContactController::class, 'destroy']);
        });
    });

    // Contactos de la vet
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::put('/{guid}', [ContactController::class, 'update']);
        Route::delete('/{guid}', [ContactController::class, 'destroy']);
    });

    // Mi perfil (usuario autenticado en este tenant)
    Route::get('/my-profile', [\App\Http\Controllers\V1\MyVetProfileController::class, 'show']);
    Route::put('/my-profile', [\App\Http\Controllers\V1\MyVetProfileController::class, 'update']);
});
