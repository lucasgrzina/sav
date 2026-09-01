<?php

use App\Http\Controllers\V1\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/push')->middleware('auth:sanctum')->group(function () {
    Route::post('/subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/subscriptions', [PushSubscriptionController::class, 'destroy']);
});
