<?php

use App\Http\Controllers\V1\VetHomeController;
use Illuminate\Support\Facades\Route;

// "Inicio" of the vet tenant. Alert blocks additionally depend on programs.alerts.read (resolved in the service layer).
Route::prefix('v1/vets/{vet}')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/home', [VetHomeController::class, 'show'])->middleware('can:programs.read');
});
