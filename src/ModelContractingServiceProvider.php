<?php

namespace MIIM\ModelContracting;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use MIIM\ModelContracting\Services\ModelContractService;

class ModelContractingServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/model-contract.php' => $this->app->configPath('model-contract.php'),
            ], 'config');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/model-contract.php', 'model-contract');

        $this->app->singleton(ModelContractService::class, function ($app) {
            return new ModelContractService();
        });

//        $this->app->make(Router::class)->aliasMiddleware('model-contracting', ModelContractingMiddleware::class);
        $this->app->make(Router::class);
    }
}
