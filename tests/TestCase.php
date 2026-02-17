<?php

namespace Shekel\SwaggerBridge\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shekel\SwaggerBridge\SwaggerBridgeServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            SwaggerBridgeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Perform any necessary environment setup
    }
}
