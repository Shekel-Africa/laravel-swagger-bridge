<?php

namespace Shekel\SwaggerBridge\Tests;

use OpenApi\Context;
use Shekel\SwaggerBridge\Mappers\ResourceMapper;
use Shekel\SwaggerBridge\Tests\Fixtures\BrokenRulesRequest;
use Shekel\SwaggerBridge\Tests\Fixtures\QuotedKeyResource;
use Shekel\SwaggerBridge\Tests\Fixtures\TestResource;
use Shekel\SwaggerBridge\Tests\Fixtures\TypedTestResource;

require_once __DIR__ . '/Fixtures/Fixtures.php';

class ResourceMapperTest extends TestCase
{
    private ResourceMapper $mapper;
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper  = new ResourceMapper();
        $this->context = new Context();
    }

    // ------------------------------------------------------------------
    // Fallback: regex-based extraction (no PHPDoc on toArray)
    // ------------------------------------------------------------------

    public function test_regex_fallback_extracts_keys_as_strings()
    {
        $schema = $this->mapper->extractSchema(TestResource::class, $this->context);

        $this->assertNotNull($schema);
        $this->assertEquals('TestResource', $schema->schema);
        $this->assertEquals('object', $schema->type);

        $properties = $schema->properties;
        $this->assertCount(1, $properties);
        $this->assertEquals('id', $properties[0]->property);
        $this->assertEquals('string', $properties[0]->type); // regex path defaults to string
    }

    public function test_returns_null_for_nonexistent_class()
    {
        $schema = $this->mapper->extractSchema('App\\DoesNotExist', $this->context);
        $this->assertNull($schema);
    }

    // ------------------------------------------------------------------
    // PHPDoc array-shape extraction
    // ------------------------------------------------------------------

    public function test_array_shape_extracts_correct_field_count()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $this->assertNotNull($schema);
        // 7 fields declared in the @return annotation
        $this->assertCount(7, $schema->properties);
    }

    public function test_array_shape_maps_integer_type()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $id = $this->findProperty($schema->properties, 'id');
        $this->assertNotNull($id);
        $this->assertEquals('integer', $id->type);
    }

    public function test_array_shape_maps_string_type()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $name = $this->findProperty($schema->properties, 'name');
        $this->assertNotNull($name);
        $this->assertEquals('string', $name->type);
    }

    public function test_array_shape_maps_float_type()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $score = $this->findProperty($schema->properties, 'score');
        $this->assertNotNull($score);
        $this->assertEquals('number', $score->type);
        $this->assertEquals('float', $score->format);
    }

    public function test_array_shape_maps_boolean_type()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $isActive = $this->findProperty($schema->properties, 'is_active');
        $this->assertNotNull($isActive);
        $this->assertEquals('boolean', $isActive->type);
    }

    public function test_array_shape_maps_array_type()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $tags = $this->findProperty($schema->properties, 'tags');
        $this->assertNotNull($tags);
        $this->assertEquals('array', $tags->type);
    }

    public function test_array_shape_marks_nullable_union_as_nullable()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $note = $this->findProperty($schema->properties, 'note');
        $this->assertNotNull($note);
        $this->assertEquals('string', $note->type);
        $this->assertTrue($note->nullable);
    }

    public function test_array_shape_marks_optional_fields_as_nullable()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $nickname = $this->findProperty($schema->properties, 'nickname');
        $this->assertNotNull($nickname);
        $this->assertTrue($nickname->nullable);
    }

    public function test_array_shape_schema_name_matches_class_short_name()
    {
        $schema = $this->mapper->extractSchema(TypedTestResource::class, $this->context);

        $this->assertEquals('TypedTestResource', $schema->schema);
        $this->assertEquals('object', $schema->type);
    }

    // ------------------------------------------------------------------
    // Quoted string keys in array shapes
    // ------------------------------------------------------------------

    public function test_quoted_key_resource_extracts_fields()
    {
        $schema = $this->mapper->extractSchema(QuotedKeyResource::class, $this->context);

        $this->assertNotNull($schema);
        $this->assertCount(2, $schema->properties);

        $userId = $this->findProperty($schema->properties, 'user_id');
        $this->assertNotNull($userId);
        $this->assertEquals('integer', $userId->type);

        $displayName = $this->findProperty($schema->properties, 'display_name');
        $this->assertNotNull($displayName);
        $this->assertEquals('string', $displayName->type);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function findProperty(array $properties, string $name): ?\OpenApi\Annotations\Property
    {
        foreach ($properties as $property) {
            if ((string) $property->property === $name) {
                return $property;
            }
        }
        return null;
    }
}
