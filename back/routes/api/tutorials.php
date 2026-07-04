<?php

use App\Http\Controllers\V1\TutorialController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/tutorials')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [TutorialController::class, 'index']);
    Route::post('/', [TutorialController::class, 'store']);
    Route::put('/{guid}', [TutorialController::class, 'update']);
    Route::delete('/{guid}', [TutorialController::class, 'destroy']);
});
