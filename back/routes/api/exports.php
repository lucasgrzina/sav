<?php

use App\Http\Controllers\V1\ExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/exports')->middleware('auth:sanctum')->group(function () {
    Route::get('/',                [ExportController::class, 'index']);
    Route::post('/',               [ExportController::class, 'store']);
    Route::get('/{guid}',          [ExportController::class, 'show']);
    Route::get('/{guid}/download', [ExportController::class, 'download']);
});
