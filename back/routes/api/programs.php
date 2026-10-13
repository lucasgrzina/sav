<?php

use App\Http\Controllers\V1\ProgramAlertController;
use App\Http\Controllers\V1\ProgramController;
use App\Http\Controllers\V1\ProgramPdfController;
use App\Http\Controllers\V1\ProgramPreviewController;
use Illuminate\Support\Facades\Route;

// Panel Tenant Vet — CRUD (sin hard-delete, solo cancelación)
Route::prefix('v1/vets/{vet}/programs')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [ProgramController::class, 'index'])->middleware('can:programs.read');
    Route::post('/', [ProgramController::class, 'store'])->middleware('can:programs.create');
    // Preview (no persiste): `/preview` se declara antes de las rutas con {guid}.
    Route::post('/preview', [ProgramPreviewController::class, 'preview'])->middleware('can:programs.create');
    Route::post('/{guid}/preview', [ProgramPreviewController::class, 'previewForProgram'])->middleware('can:programs.update');
    // Delivery view of the program ("Avisos" tab). Read-only; clients roles do not hold the permission.
    Route::get('/{guid}/alerts', [ProgramAlertController::class, 'index'])->middleware('can:programs.alerts.read');
    Route::post('/{guid}/alerts/rebuild', [ProgramAlertController::class, 'rebuild'])->middleware('can:programs.update');
    Route::post('/{guid}/alerts/{recipientGuid}/retry', [ProgramAlertController::class, 'retry'])->middleware('can:programs.update');
    Route::get('/{guid}', [ProgramController::class, 'show'])->middleware('can:programs.read');
    Route::put('/{guid}', [ProgramController::class, 'update'])->middleware('can:programs.update');
    Route::post('/{guid}/cancel', [ProgramController::class, 'cancel'])->middleware('can:programs.update');

    // PDF de detalle (descarga async) + envío por WhatsApp — DEC-01/DEC-06. Reusan
    // `programs.read`: son operaciones de lectura + notificación, no alteran el programa.
    Route::post('/{guid}/pdf', [ProgramPdfController::class, 'requestPdf'])->middleware('can:programs.read');
    Route::get('/{guid}/share-recipients', [ProgramPdfController::class, 'shareRecipients'])->middleware('can:programs.read');
    Route::post('/{guid}/share', [ProgramPdfController::class, 'share'])->middleware('can:programs.read');
});
