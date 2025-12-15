<?php

namespace MIIM\ModelContracting;

use Illuminate\Support\ServiceProvider;
use MIIM\ModelContracting\Commands\GenerateModelResourceCommand;
use MIIM\ModelContracting\Services\ModelRegistryService;
use MIIM\ModelContracting\Services\ModelMetaService;
use MIIM\ModelContracting\Services\ModelApiService;

class ModelContractingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/model-resource.php', 'model-resource'
        );

        $this->app->singleton(ModelRegistryService::class, function ($app) {
            return new ModelRegistryService();
        });

        $this->app->singleton(ModelMetaService::class, function ($app) {
            return new ModelMetaService($app->make(ModelRegistryService::class));
        });

        $this->app->singleton(ModelApiService::class, function ($app) {
            return new ModelApiService(
                $app->make(ModelMetaService::class),
                $app->make(ModelRegistryService::class)
            );
        });
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModelResourceCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/model-resource.php' => config_path('model-resource.php'),
            ], 'model-resource-config');
        }

        // Автоматическая загрузка существующих ресурсов
        $this->loadExistingResources();
    }

    private function loadExistingResources(): void
    {
        $path = config('model-resource.path', app_path('ModelResources'));

        if (!file_exists($path)) {
            return;
        }

        $namespace = config('model-resource.namespace', 'App\\ModelResources');

        foreach (glob($path . '/*.php') as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullClassName = $namespace . '\\' . $className;

            if (class_exists($fullClassName)) {
                $fullClassName::boot();
            }
        }
    }
}
