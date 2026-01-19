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
        $this->app->singleton(Services\ModelRegistryService::class);
        $this->app->singleton(Services\ModelMetaService::class);
        $this->app->singleton(Services\ModelApiService::class);
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModelResourceCommand::class,
            ]);
        }

        // Автоматическая загрузка существующих ресурсов
        $this->loadExistingResources();
    }

    private function loadExistingResources(): void
    {
        $path = app_path('Contracting');
        $namespace = 'App\\Contracting';

        if (!file_exists($path)) {
            return;
        }

        foreach (glob($path . '/*ContractingResource.php') as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullClassName = $namespace . '\\' . $className;

            if (class_exists($fullClassName) && method_exists($fullClassName, 'boot')) {
                $fullClassName::boot();
            }
        }
    }
}
