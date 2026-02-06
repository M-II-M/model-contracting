<?php

use Illuminate\Support\Facades\Route;
use MIIM\ModelContracting\Http\Controllers\ModelContractingController;

Route::get('/admin/{alias}/meta', [ModelContractingController::class, 'getMeta'])
    ->name('model-contracting.meta');

Route::get('/admin/{alias}', [ModelContractingController::class, 'index'])
    ->name('model-contracting.index');

Route::post('/admin/{alias}', [ModelContractingController::class, 'store'])
    ->name('model-contracting.create');

Route::patch('/admin/{alias}', [ModelContractingController::class, 'update'])
    ->name('model-contracting.update');

Route::delete('/admin/{alias}', [ModelContractingController::class, 'destroy'])
    ->name('model-contracting.delete');
