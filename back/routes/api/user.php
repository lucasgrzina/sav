<?php

use App\Http\Controllers\V1\UserVetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/user')->middleware('auth:sanctum')->group(function () {
    Route::get('/vets', [UserVetController::class, 'index']);
});
