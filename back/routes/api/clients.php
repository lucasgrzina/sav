<?php

use App\Http\Controllers\V1\AdminClientController;
use App\Http\Controllers\V1\ClientController;
use App\Http\Controllers\V1\ClientOwnerController;
use App\Http\Controllers\V1\ClientStaffController;
use App\Http\Controllers\V1\ContactController;
use App\Http\Controllers\V1\EstablishmentController;
use Illuminate\Support\Facades\Route;

// --- Panel SuperAdmin ---
Route::prefix('v1/admin/clients')->middleware('auth:sanctum')->group(function () {
    Route::get('/',       [AdminClientController::class, 'index'])->middleware('can:clients.read');
    Route::post('/',      [AdminClientController::class, 'store'])->middleware('can:clients.create');

    // IMPORTANTE: /lookup debe ir ANTES de /{guid} para evitar colisión de rutas
    Route::get('/lookup', [AdminClientController::class, 'lookup'])->middleware('can:clients.read');

    Route::get('/{guid}', [AdminClientController::class, 'show'])->middleware('can:clients.read');
    Route::put('/{guid}', [AdminClientController::class, 'update'])->middleware('can:clients.update');

    // Gestión de vínculo client-vet desde el lado admin (sin middleware vet.tenant)
    Route::post('/{clientGuid}/vets',             [AdminClientController::class, 'linkVet'])->middleware('can:clients.create');
    Route::delete('/{clientGuid}/vets/{vetGuid}', [AdminClientController::class, 'unlinkVet'])->middleware('can:clients.delete');

    // Establecimientos de client (panel admin)
    Route::get('/{guid}/establishments',          [AdminClientController::class, 'establishmentIndex'])  ->middleware('can:establishments.read');
    Route::post('/{guid}/establishments',         [AdminClientController::class, 'establishmentStore'])  ->middleware('can:establishments.create');
    Route::put('/{guid}/establishments/{estGuid}',[AdminClientController::class, 'establishmentUpdate']) ->middleware('can:establishments.update');
    Route::delete('/{guid}/establishments/{estGuid}',[AdminClientController::class, 'establishmentDestroy'])->middleware('can:establishments.delete');

    // Staff de client (panel admin)
    Route::get('/{guid}/staff',                                          [AdminClientController::class, 'staffIndex'])    ->middleware('can:clients.staff.read');
    Route::post('/{guid}/staff',                                         [AdminClientController::class, 'staffStore'])    ->middleware('can:clients.staff.create');
    Route::get('/{guid}/staff/{profileGuid}',        [AdminClientController::class, 'staffShow'])     ->middleware('can:clients.staff.read');
    Route::put('/{guid}/staff/{profileGuid}',        [AdminClientController::class, 'staffUpdate'])   ->middleware('can:clients.staff.update');
    Route::patch('/{guid}/staff/{profileGuid}/role', [AdminClientController::class, 'staffChangeRole'])->middleware('can:clients.staff.update');
    Route::delete('/{guid}/staff/{profileGuid}',     [AdminClientController::class, 'staffDestroy'])  ->middleware('can:clients.staff.delete');
});

Route::prefix('v1/vets/{vet}')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {

    // Clients
    Route::prefix('clients')->group(function () {
        Route::get('/',          [ClientController::class, 'index'])->middleware('can:clients.read');
        Route::post('/',         [ClientController::class, 'store'])->middleware('can:clients.create');

        // IMPORTANTE: /lookup debe ir ANTES de /{guid} para evitar colisión
        Route::get('/lookup',    [ClientController::class, 'lookup'])->middleware('can:clients.read');

        Route::get('/{guid}',    [ClientController::class, 'show'])->middleware('can:clients.read');
        Route::put('/{guid}',    [ClientController::class, 'update'])->middleware('can:clients.update');
        Route::delete('/{guid}', [ClientController::class, 'destroy'])->middleware('can:clients.delete');

        // Vincular client existente al tenant
        Route::post('/{guid}/link', [ClientController::class, 'link'])->middleware('can:clients.create');

        // Owners de un client
        Route::prefix('/{guid}/owners')->group(function () {
            Route::get('/',  [ClientOwnerController::class, 'index'])->middleware('can:clients.owners.read');
            Route::post('/', [ClientOwnerController::class, 'store'])->middleware('can:clients.owners.create');
        });

        // Contactos de un client
        Route::prefix('/{client}/contacts')->group(function () {
            Route::get('/',          [ContactController::class, 'index'])->middleware('can:clients.read');
            Route::post('/',         [ContactController::class, 'store'])->middleware('can:clients.update');
            Route::put('/{guid}',    [ContactController::class, 'update'])->middleware('can:clients.update');
            Route::delete('/{guid}', [ContactController::class, 'destroy'])->middleware('can:clients.update');
        });

        // Establecimientos de un client
        Route::prefix('/{client}/establishments')->group(function () {
            Route::get('/',          [EstablishmentController::class, 'index'])->middleware('can:establishments.read');
            Route::post('/',         [EstablishmentController::class, 'store'])->middleware('can:establishments.create');
            Route::put('/{guid}',    [EstablishmentController::class, 'update'])->middleware('can:establishments.update');
            Route::delete('/{guid}', [EstablishmentController::class, 'destroy'])->middleware('can:establishments.delete');
        });

        // Staff de un client (panel tenant)
        // IMPORTANTE: rutas estáticas ANTES de las dinámicas (igual que vets.php)
        Route::prefix('/{client}/staff')->group(function () {
            Route::get('/',                    [ClientStaffController::class, 'index'])          ->middleware('can:clients.staff.read');
            Route::post('/',                   [ClientStaffController::class, 'store'])          ->middleware('can:clients.staff.create');
            Route::get('/lookup',              [ClientStaffController::class, 'lookup'])         ->middleware('can:clients.staff.read');
            Route::post('/new-user',           [ClientStaffController::class, 'createAndAssign'])->middleware('can:clients.staff.create');
            Route::get('/{guid}',              [ClientStaffController::class, 'show'])           ->middleware('can:clients.staff.read');
            Route::put('/{guid}',              [ClientStaffController::class, 'update'])         ->middleware('can:clients.staff.update');
            Route::delete('/{guid}',           [ClientStaffController::class, 'destroy'])        ->middleware('can:clients.staff.delete');
            Route::patch('/{guid}/role',        [ClientStaffController::class, 'changeRole'])     ->middleware('can:clients.staff.update');
            Route::patch('/{guid}/toggle-block', [ClientStaffController::class, 'toggleBlock'])  ->middleware('can:clients.staff.update');
        });
    });
});
