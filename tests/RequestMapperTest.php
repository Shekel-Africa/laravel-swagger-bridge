<?php

namespace Shekel\SwaggerBridge\Tests;

use OpenApi\Context;
use OpenApi\Annotations\Get;
use OpenApi\Annotations\Post;
use OpenApi\Generator;
use Shekel\SwaggerBridge\Mappers\RequestMapper;
use Shekel\SwaggerBridge\Tests\Fixtures\TestGetRequest;
use Shekel\SwaggerBridge\Tests\Fixtures\TestPostRequest;
use Shekel\SwaggerBridge\Tests\Fixtures\BrokenRulesRequest;

require_once __DIR__ . '/Fixtures/Fixtures.php';

class RequestMapperTest extends TestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new RequestMapper();
    }

    public function test_it_maps_get_rules_to_query_parameters()
    {
        $operation = new Get(['method' => 'get', '_context' => new Context()]);
        
        $this->mapper->map($operation, TestGetRequest::class);

        $parameters = $operation->parameters;
        $this->assertIsArray($parameters);
        $this->assertCount(2, $parameters);
        
        $search = $parameters[0];
        $this->assertEquals('search', (string)$search->name);
        $this->assertEquals('query', (string)$search->in);
        $this->assertEquals('string', (string)$search->schema->type);

        $page = $parameters[1];
        $this->assertEquals('page', (string)$page->name);
        $this->assertEquals('integer', (string)$page->schema->type);
    }

    public function test_it_maps_post_rules_to_request_body()
    {
        $operation = new Post(['method' => 'post', '_context' => new Context()]);
        
        $this->mapper->map($operation, TestPostRequest::class);

        $requestBody = $operation->requestBody;
        $this->assertNotSame(Generator::UNDEFINED, $requestBody);
        
        $jsonContent = $requestBody->content['application/json'];
        $properties = $jsonContent->properties;
        
        $this->assertIsArray($properties);
        $this->assertCount(7, $properties);
        $this->assertEquals('name', (string)$properties[0]->property);
        $this->assertEquals('string', (string)$properties[0]->type);
        $this->assertContains('name', (array)$jsonContent->required);

        $this->assertEquals('email', (string)$properties[1]->property);
        $this->assertEquals('email', (string)$properties[1]->format);

        $this->assertEquals('type', (string)$properties[5]->property);
        $this->assertEquals(['admin', 'user'], (array)$properties[5]->enum);
    }

    public function test_it_maps_query_rules_in_post_requests()
    {
        $operation = new Post(['method' => 'post', '_context' => new Context()]);
        
        $this->mapper->map($operation, TestPostRequest::class);

        $parameters = $operation->parameters;
        $this->assertIsArray($parameters);
        $this->assertCount(1, $parameters);
        $this->assertEquals('notify', (string)$parameters[0]->name);
        $this->assertEquals('query', (string)$parameters[0]->in);
        $this->assertEquals('boolean', (string)$parameters[0]->schema->type);
    }

    public function test_it_gracefully_handles_broken_rules_method()
    {
        $operation = new Get(['method' => 'get', '_context' => new Context()]);
        
        $this->mapper->map($operation, BrokenRulesRequest::class);
        
        $this->assertSame(Generator::UNDEFINED, $operation->parameters);
    }
}
