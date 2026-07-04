<?php

use App\Http\Controllers\V1\CountryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/countries')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [CountryController::class, 'index']);
    Route::get('/{guid}/document-types', [CountryController::class, 'documentTypes']);
});
