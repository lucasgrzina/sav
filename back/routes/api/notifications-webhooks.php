<?php

use App\Notifications\Http\Controllers\KapsoWebhookController;
use App\Notifications\Http\Controllers\TwilioWebhookController;
use Illuminate\Support\Facades\Route;

// Callback de proveedor: sin auth:sanctum a propósito — no hay usuario de la plataforma
// detrás, la firma HMAC del middleware es la autenticación.
Route::post('v1/webhooks/kapso', KapsoWebhookController::class)
    ->middleware('kapso.signature')
    ->name('webhooks.kapso');

// Callback de proveedor: sin auth:sanctum a propósito — no hay usuario de la plataforma
// detrás, la firma HMAC del middleware es la autenticación.
Route::post('v1/webhooks/twilio', TwilioWebhookController::class)
    ->middleware('twilio.signature')
    ->name('webhooks.twilio');
