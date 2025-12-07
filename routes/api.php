<?php

use Illuminate\Support\Facades\Route;
use MIIM\ModelContracting\Http\Controllers\ModelContractingController;

Route::prefix(config('model-contracting.route_prefix'))
    ->middleware(config('model-contracting.route_middleware'))
    ->group(function () {

        Route::get('/{alias}/meta', [ModelContractingController::class, 'getMeta'])
            ->name('model-contracting.meta');

        Route::get('/{alias}', [ModelContractingController::class, 'getInstances'])
            ->name('model-contracting.index');

        Route::post('/{alias}', [ModelContractingController::class, 'createInstance'])
            ->name('model-contracting.create');

        Route::patch('/{alias}', [ModelContractingController::class, 'updateInstances'])
            ->name('model-contracting.update');

        Route::delete('/{alias}', [ModelContractingController::class, 'deleteInstances'])
            ->name('model-contracting.delete');
    });
