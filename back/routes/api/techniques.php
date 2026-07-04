<?php

use App\Http\Controllers\V1\AdminTechniqueController;
use App\Http\Controllers\V1\TechniqueController;
use Illuminate\Support\Facades\Route;

// Panel SuperAdmin — CRUD completo
Route::prefix('v1/admin/techniques')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AdminTechniqueController::class, 'index'])->middleware('can:techniques.read');
    Route::post('/', [AdminTechniqueController::class, 'store'])->middleware('can:techniques.create');
    Route::get('/{guid}', [AdminTechniqueController::class, 'show'])->middleware('can:techniques.read');
    Route::put('/{guid}', [AdminTechniqueController::class, 'update'])->middleware('can:techniques.update');
    Route::delete('/{guid}', [AdminTechniqueController::class, 'destroy'])->middleware('can:techniques.delete');
});

// API panel Vet — solo lectura
// IMPORTANTE: /protocols debe ir ANTES de /{guid} para evitar colisión de rutas
Route::prefix('v1/techniques')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [TechniqueController::class, 'index']);
    Route::get('/protocols', [TechniqueController::class, 'protocols']);
    Route::get('/{guid}/protocols', [TechniqueController::class, 'techniqueProtocols']);
});
