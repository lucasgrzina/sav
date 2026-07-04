<?php

use App\Http\Controllers\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('/',              [NotificationController::class, 'index']);
    Route::get('/latest',        [NotificationController::class, 'latest']);
    Route::patch('/read-all',    [NotificationController::class, 'markAllAsRead']);
    Route::patch('/{guid}/read', [NotificationController::class, 'markAsRead']);
});
