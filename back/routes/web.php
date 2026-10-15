<?php

use App\Http\Controllers\OpsArtisanController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Whitelisted artisan runner for hosts without shell access. Disabled unless OPS_ARTISAN_TOKEN is set.
Route::post('/_ops/artisan/{command}', OpsArtisanController::class)
    ->middleware('throttle:5,1')
    ->withoutMiddleware(ValidateCsrfToken::class);
