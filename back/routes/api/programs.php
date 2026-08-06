<?php

use App\Http\Controllers\V1\ProgramController;
use Illuminate\Support\Facades\Route;

// Panel Tenant Vet — CRUD (sin hard-delete, solo cancelación)
Route::prefix('v1/vets/{vet}/programs')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [ProgramController::class, 'index'])->middleware('can:programs.read');
    Route::post('/', [ProgramController::class, 'store'])->middleware('can:programs.create');
    Route::get('/{guid}', [ProgramController::class, 'show'])->middleware('can:programs.read');
    Route::put('/{guid}', [ProgramController::class, 'update'])->middleware('can:programs.update');
    Route::post('/{guid}/cancel', [ProgramController::class, 'cancel'])->middleware('can:programs.update');
});
