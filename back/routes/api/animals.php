<?php

use App\Http\Controllers\V1\AnimalController;
use Illuminate\Support\Facades\Route;

// Búsqueda de animales por cliente, para alimentar el picker de Programa (DEC-10).
// Reutiliza el permiso programs.read (ver nota en el plan de Programa).
Route::prefix('v1/vets/{vet}/clients/{client}/animals')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [AnimalController::class, 'indexForClient'])->middleware('can:programs.read');
});
