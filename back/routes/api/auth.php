<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\PasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {

    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
    });

    Route::prefix('verify-account')->middleware('throttle:10,1')->group(function () {
        Route::post('verify-code', [AuthController::class, 'verifyCode']);
        Route::post('resend-code', [AuthController::class, 'resendCode']);
    });

    Route::prefix('forgot-password')->middleware('throttle:5,1')->group(function () {
        Route::post('verify-email', [PasswordController::class, 'verifyEmail']);
        Route::post('verify-code', [PasswordController::class, 'verifyCode']);
        Route::post('resend-code', [PasswordController::class, 'resendCode']);
        Route::post('reset-password', [PasswordController::class, 'resetPassword']);
    });

    Route::post('invitation/accept', [AuthController::class, 'acceptInvitation'])
        ->middleware('throttle:5,1');
});
