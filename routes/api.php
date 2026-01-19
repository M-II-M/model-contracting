<?php

use Illuminate\Support\Facades\Route;
use MIIM\ModelContracting\Http\Controllers\ModelContractingController;

Route::prefix('api')
    ->group(function () {

        Route::get('/{alias}/meta', [ModelContractingController::class, 'getMeta'])
            ->name('model-contracting.meta');

        Route::get('/{alias}', [ModelContractingController::class, 'index'])
            ->name('model-contracting.index');

        Route::post('/{alias}', [ModelContractingController::class, 'store'])
            ->name('model-contracting.create');

        Route::patch('/{alias}', [ModelContractingController::class, 'update'])
            ->name('model-contracting.update');

        Route::delete('/{alias}', [ModelContractingController::class, 'destroy'])
            ->name('model-contracting.delete');
    });
