<?php

namespace Shekel\SwaggerBridge\Tests;

use OpenApi\Context;
use OpenApi\Annotations\Get;
use OpenApi\Generator;
use ReflectionMethod;
use Shekel\SwaggerBridge\Mappers\ResponseMapper;
use Shekel\SwaggerBridge\Tests\Fixtures\TestController;

require_once __DIR__ . '/Fixtures/Fixtures.php';

class ResponseMapperTest extends TestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ResponseMapper();
    }

    public function test_it_maps_resource_collection_response()
    {
        $operation = new Get(['method' => 'get', '_context' => new Context()]);
        $reflection = new ReflectionMethod(TestController::class, 'index');
        
        $analysis = new \OpenApi\Analysis([], new Context());
        $analysis->openapi = new \OpenApi\Annotations\OpenApi(['_context' => $analysis->context]);

        $this->mapper->map($operation, $reflection, $analysis);

        $responses = $operation->responses;
        $this->assertNotEmpty($responses);
        
        $success = $responses[0];
        $this->assertEquals('200', (string)$success->response);
        
        $json = $success->content['application/json'];
        $dataProperty = $json->properties[0];
        
        $this->assertEquals('data', (string)$dataProperty->property);
        $this->assertEquals('array', (string)$dataProperty->type);
        $this->assertEquals('#/components/schemas/TestResource', (string)$dataProperty->items->ref);
    }

    public function test_it_maps_single_resource_response()
    {
        $operation = new Get(['method' => 'get', '_context' => new Context()]);
        $reflection = new ReflectionMethod(TestController::class, 'store');
        
        $analysis = new \OpenApi\Analysis([], new Context());
        $analysis->openapi = new \OpenApi\Annotations\OpenApi(['_context' => $analysis->context]);

        $this->mapper->map($operation, $reflection, $analysis);

        $success = $operation->responses[0];
        $json = $success->content['application/json'];
        $dataProperty = $json->properties[0];
        
        $this->assertEquals('data', (string)$dataProperty->property);
        $this->assertEquals('#/components/schemas/TestResource', (string)$dataProperty->ref);
    }

    public function test_it_handles_missing_type_hints()
    {
        $operation = new Get(['method' => 'get', '_context' => new Context()]);
        $reflection = new ReflectionMethod(TestController::class, 'noTypeHint');
        
        $analysis = new \OpenApi\Analysis([], new Context());
        $analysis->openapi = new \OpenApi\Annotations\OpenApi(['_context' => $analysis->context]);

        $this->mapper->map($operation, $reflection, $analysis);

        $this->assertNotSame(Generator::UNDEFINED, $operation->responses);
        $this->assertEquals('200', (string)$operation->responses[0]->response);
    }
}
