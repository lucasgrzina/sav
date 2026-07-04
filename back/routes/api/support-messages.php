<?php

use App\Http\Controllers\V1\SupportMessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/support-messages')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [SupportMessageController::class, 'index']);
    Route::post('/', [SupportMessageController::class, 'store']);
    Route::get('/{guid}', [SupportMessageController::class, 'show']);
    Route::delete('/{guid}', [SupportMessageController::class, 'destroy']);
    Route::post('/{guid}/replies', [SupportMessageController::class, 'addReply']);
    Route::patch('/{guid}/close', [SupportMessageController::class, 'close']);
});
