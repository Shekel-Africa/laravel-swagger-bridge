<?php

namespace Shekel\SwaggerBridge;

use Illuminate\Support\ServiceProvider;
use Shekel\SwaggerBridge\Console\Commands\MakeQueryRequest;

class SwaggerBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeQueryRequest::class,
            ]);

            // Allow host applications to publish and customise the stubs.
            $this->publishes([
                __DIR__ . '/../stubs/query-request.stub' => resource_path('stubs/swagger-bridge/query-request.stub'),
            ], 'swagger-bridge-stubs');
        }
    }
}
