<?php

use App\Http\Controllers\V1\ProgramPdfController;
use Illuminate\Support\Facades\Route;

// DEC-08: URL pública firmada y temporal para que Twilio pueda descargar el PDF de un
// programa compartido por WhatsApp. Sin auth:sanctum a propósito — Twilio no autentica
// como usuario de la app; la firma de Laravel (con expiración) es el control equivalente.
Route::get('/v1/programs/shared-pdf/{guid}', [ProgramPdfController::class, 'servePublicPdf'])
    ->middleware('signed')
    ->name('programs.shared-pdf');

// Botón "Descargar el programa" del template de WhatsApp de ProgramCreated: el click llega
// directo desde el chat del destinatario, sin sesión de la app. Igual que arriba, sin
// auth:sanctum a propósito; la firma de Laravel es el control equivalente. A diferencia del
// share manual, este link es PERMANENTE (URL::signedRoute, sin expiración) porque el
// programa puede consultarse en cualquier momento de su vida. vet_id viaja como parámetro de
// la ruta para que la firma lo proteja de manipulación (regla dura #4: findByGuidForVet).
Route::get('/v1/programs/{guid}/download-pdf', [ProgramPdfController::class, 'downloadPublic'])
    ->middleware('signed')
    ->name('programs.public-download-pdf');
