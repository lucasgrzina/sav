<?php

use App\Http\Controllers\V1\AdminProtocolController;
use App\Http\Controllers\V1\VetProtocolController;
use Illuminate\Support\Facades\Route;

// Panel SuperAdmin — CRUD completo
Route::prefix('v1/admin/protocols')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AdminProtocolController::class, 'index'])->middleware('can:protocols.read');
    Route::post('/', [AdminProtocolController::class, 'store'])->middleware('can:protocols.create');
    Route::post('/{guid}/replicate', [AdminProtocolController::class, 'replicate'])->middleware('can:protocols.create');
    Route::get('/{guid}/simulate', [AdminProtocolController::class, 'simulate'])->middleware('can:protocols.update');
    Route::get('/{guid}', [AdminProtocolController::class, 'show'])->middleware('can:protocols.read');
    Route::put('/{guid}', [AdminProtocolController::class, 'update'])->middleware('can:protocols.update');
    Route::delete('/{guid}', [AdminProtocolController::class, 'destroy'])->middleware('can:protocols.delete');
});

// Panel Tenant Vet — capa "propia" + lectura de capa plantilla global
Route::prefix('v1/vets/{vet}/protocols')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetProtocolController::class, 'index'])->middleware('can:protocols.read');
    Route::post('/', [VetProtocolController::class, 'store'])->middleware('can:protocols.create');
    Route::get('/{guid}', [VetProtocolController::class, 'show'])->middleware('can:protocols.read');
    Route::put('/{guid}', [VetProtocolController::class, 'update'])->middleware('can:protocols.update');
    Route::delete('/{guid}', [VetProtocolController::class, 'destroy'])->middleware('can:protocols.delete');
});
