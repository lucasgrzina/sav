<?php

use App\Http\Controllers\V1\ProgramController;
use App\Http\Controllers\V1\ProgramPdfController;
use Illuminate\Support\Facades\Route;

// Panel Tenant Vet — CRUD (sin hard-delete, solo cancelación)
Route::prefix('v1/vets/{vet}/programs')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [ProgramController::class, 'index'])->middleware('can:programs.read');
    Route::post('/', [ProgramController::class, 'store'])->middleware('can:programs.create');
    Route::get('/{guid}', [ProgramController::class, 'show'])->middleware('can:programs.read');
    Route::put('/{guid}', [ProgramController::class, 'update'])->middleware('can:programs.update');
    Route::post('/{guid}/cancel', [ProgramController::class, 'cancel'])->middleware('can:programs.update');

    // PDF de detalle (descarga async) + envío por WhatsApp — DEC-01/DEC-06. Reusan
    // `programs.read`: son operaciones de lectura + notificación, no alteran el programa.
    Route::post('/{guid}/pdf', [ProgramPdfController::class, 'requestPdf'])->middleware('can:programs.read');
    Route::get('/{guid}/share-recipients', [ProgramPdfController::class, 'shareRecipients'])->middleware('can:programs.read');
    Route::post('/{guid}/share', [ProgramPdfController::class, 'share'])->middleware('can:programs.read');
});
