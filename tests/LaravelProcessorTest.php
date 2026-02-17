<?php

namespace Shekel\SwaggerBridge\Tests;

use OpenApi\Analysis;
use OpenApi\Annotations\OpenApi;
use OpenApi\Context;
use OpenApi\Generator;
use Illuminate\Support\Facades\Route;
use Shekel\SwaggerBridge\Processors\LaravelProcessor;
use Shekel\SwaggerBridge\Tests\Fixtures\TestController;

require_once __DIR__ . '/Fixtures/Fixtures.php';

class LaravelProcessorTest extends TestCase
{
    public function test_it_processes_laravel_routes()
    {
        Route::get('/api/v4/test', [TestController::class, 'index'])->middleware('auth:api');
        Route::post('/api/v4/test', [TestController::class, 'store']);

        $analysis = new Analysis([], new Context());
        $analysis->openapi = new OpenApi(['_context' => $analysis->context]);
        
        $processor = new LaravelProcessor();
        $processor($analysis);

        $paths = $analysis->openapi->paths;
        $this->assertIsArray($paths);
        $this->assertCount(1, $paths);
        
        $pathItem = $paths[0];
        $this->assertEquals('/api/v4/test', $pathItem->path);
        
        // Check GET operation
        $this->assertNotSame(Generator::UNDEFINED, $pathItem->get);
        $this->assertEquals('Test Summary', (string)$pathItem->get->summary);
        
        // Check security for GET (middleware auth:api)
        $this->assertNotSame(Generator::UNDEFINED, $pathItem->get->security);
        
        // Check POST operation
        $this->assertNotSame(Generator::UNDEFINED, $pathItem->post);
        $this->assertEquals('Create Summary', (string)$pathItem->post->summary);
    }

    public function test_it_handles_path_parameters()
    {
        Route::get('/api/v4/test/{id}', [TestController::class, 'show']);

        $analysis = new Analysis([], new Context());
        $analysis->openapi = new OpenApi(['_context' => $analysis->context]);
        
        $processor = new LaravelProcessor();
        $processor($analysis);

        $pathItem = $analysis->openapi->paths[0];
        $parameters = $pathItem->get->parameters;
        
        $this->assertIsArray($parameters);
        $this->assertCount(1, $parameters);
        $this->assertEquals('id', $parameters[0]->name);
        $this->assertEquals('path', $parameters[0]->in);
    }

    public function test_it_detects_path_parameter_types()
    {
        // 1. Test via type-hints
        Route::get('/api/v4/typed/{id}/{active}', [TestController::class, 'showTyped']);
        // 2. Test via constraints
        Route::get('/api/v4/constrained/{count}', [TestController::class, 'show'])->where('count', '[0-9]+');
        // 3. Test via Model binding
        Route::get('/api/v4/model/{model}', [TestController::class, 'showModel']);

        $analysis = new Analysis([], new Context());
        $analysis->openapi = new OpenApi(['_context' => $analysis->context]);
        
        $processor = new LaravelProcessor();
        $processor($analysis);

        $paths = $analysis->openapi->paths;

        // Check typed
        $typedPath = collect($paths)->first(fn($p) => $p->path === '/api/v4/typed/{id}/{active}');
        $this->assertEquals('integer', $typedPath->get->parameters[0]->schema->type);
        $this->assertEquals('boolean', $typedPath->get->parameters[1]->schema->type);

        // Check constrained
        $constPath = collect($paths)->first(fn($p) => $p->path === '/api/v4/constrained/{count}');
        $this->assertEquals('integer', $constPath->get->parameters[0]->schema->type);

        // Check model
        $modelPath = collect($paths)->first(fn($p) => $p->path === '/api/v4/model/{model}');
        $this->assertEquals('string', $modelPath->get->parameters[0]->schema->type);
        $this->assertEquals('uuid', $modelPath->get->parameters[0]->schema->format);
    }

    public function test_it_skips_closure_routes()
    {
        Route::get('/api/v4/closure', function() { return 'ok'; });

        $analysis = new Analysis([], new Context());
        $analysis->openapi = new OpenApi(['_context' => $analysis->context]);
        
        $processor = new LaravelProcessor();
        $processor($analysis);

        $this->assertTrue($analysis->openapi->paths === Generator::UNDEFINED || empty($analysis->openapi->paths));
    }
}
