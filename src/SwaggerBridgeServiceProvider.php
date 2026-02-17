<?php

namespace Shekel\SwaggerBridge;

use Illuminate\Support\ServiceProvider;

class SwaggerBridgeServiceProvider extends ServiceProvider
{
    public function register()
    {
        // For local development, we'll manually add the processor to L5 Swagger config if possible,
        // or provide a command to do it.
    }

    public function boot()
    {
        //
    }
}
