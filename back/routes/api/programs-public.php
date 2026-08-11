<?php

use App\Http\Controllers\V1\ProgramPdfController;
use Illuminate\Support\Facades\Route;

// DEC-08: URL pública firmada y temporal para que Twilio pueda descargar el PDF de un
// programa compartido por WhatsApp. Sin auth:sanctum a propósito — Twilio no autentica
// como usuario de la app; la firma de Laravel (con expiración) es el control equivalente.
Route::get('/v1/programs/shared-pdf/{guid}', [ProgramPdfController::class, 'servePublicPdf'])
    ->middleware('signed')
    ->name('programs.shared-pdf');
