<?php

namespace Shekel\SwaggerBridge\Mappers;

use OpenApi\Annotations\Schema;
use OpenApi\Annotations\Property;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

class ResourceMapper
{
    private Lexer $lexer;
    private PhpDocParser $phpDocParser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        $this->lexer = new Lexer($config);
    }

    public function extractSchema(string $className, $context): ?Schema
    {
        if (!class_exists($className)) return null;

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return null;
        }

        // First try to extract schema from PHPDoc @return annotation on toArray()
        $schema = $this->extractSchemaFromPhpDoc($reflection, $className, $context);
        if ($schema) {
            return $schema;
        }

        // Fall back to regex-based extraction
        return $this->extractSchemaFromRegex($reflection, $className, $context);
    }

    private function extractSchemaFromPhpDoc(\ReflectionClass $reflection, string $className, $context): ?Schema
    {
        if (!$reflection->hasMethod('toArray')) {
            return null;
        }

        $method = $reflection->getMethod('toArray');
        $docComment = $method->getDocComment();
        if (!$docComment) {
            return null;
        }

        $tokens = new TokenIterator($this->lexer->tokenize($docComment));
        $phpDocNode = $this->phpDocParser->parse($tokens);

        $returnTags = $phpDocNode->getReturnTagValues();
        if (empty($returnTags)) {
            return null;
        }

        $returnType = $returnTags[0]->type;

        // Unwrap nullable: ?array{...} -> array{...}
        if ($returnType instanceof NullableTypeNode) {
            $returnType = $returnType->type;
        }

        if ($returnType instanceof ArrayShapeNode) {
            return $this->buildSchemaFromArrayShape($returnType, $className, $context);
        }

        return null;
    }

    private function buildSchemaFromArrayShape(ArrayShapeNode $arrayShape, string $className, $context): ?Schema
    {
        $properties = [];

        foreach ($arrayShape->items as $item) {
            if ($item->keyName === null) {
                continue;
            }

            $fieldName = match (true) {
                $item->keyName instanceof ConstExprStringNode  => $item->keyName->value,
                $item->keyName instanceof ConstExprIntegerNode => (string) $item->keyName->value,
                $item->keyName instanceof IdentifierTypeNode   => $item->keyName->name,
                default                                        => (string) $item->keyName,
            };

            [$openApiType, $openApiFormat, $isNullable] = $this->mapTypeToOpenApi($item->valueType);

            $propertyData = [
                'property' => $fieldName,
                'type'     => $openApiType,
                '_context' => $context,
            ];

            if ($openApiFormat !== null) {
                $propertyData['format'] = $openApiFormat;
            }

            if ($isNullable || $item->optional) {
                $propertyData['nullable'] = true;
            }

            $properties[] = new Property($propertyData);
        }

        if (empty($properties)) {
            return null;
        }

        return new Schema([
            'schema'     => $this->getShortName($className),
            'properties' => $properties,
            'type'       => 'object',
            '_context'   => $context,
        ]);
    }

    /**
     * Maps a PHPDoc TypeNode to an OpenAPI [type, format, isNullable] triple.
     *
     * @return array{string, string|null, bool}
     */
    private function mapTypeToOpenApi(TypeNode $type): array
    {
        if ($type instanceof NullableTypeNode) {
            [$innerType, $innerFormat] = $this->mapTypeToOpenApi($type->type);
            return [$innerType, $innerFormat, true];
        }

        if ($type instanceof UnionTypeNode) {
            $isNullable = false;
            $nonNullTypes = [];
            foreach ($type->types as $t) {
                if ($t instanceof IdentifierTypeNode && $t->name === 'null') {
                    $isNullable = true;
                } else {
                    $nonNullTypes[] = $t;
                }
            }
            if (count($nonNullTypes) === 1) {
                [$innerType, $innerFormat] = $this->mapTypeToOpenApi($nonNullTypes[0]);
                return [$innerType, $innerFormat, $isNullable];
            }
            return ['string', null, $isNullable];
        }

        if ($type instanceof IdentifierTypeNode) {
            return match ($type->name) {
                'int', 'integer',
                'positive-int', 'negative-int',
                'non-positive-int', 'non-negative-int' => ['integer', null, false],
                'float', 'double'                      => ['number', 'float', false],
                'bool', 'boolean'                      => ['boolean', null, false],
                'array', 'list',
                'non-empty-array', 'non-empty-list'    => ['array', null, false],
                default                                => ['string', null, false],
            };
        }

        if ($type instanceof GenericTypeNode && $type->type instanceof IdentifierTypeNode) {
            if (in_array($type->type->name, ['array', 'list', 'non-empty-array', 'non-empty-list'], true)) {
                return ['array', null, false];
            }
        }

        if ($type instanceof ArrayShapeNode) {
            return ['object', null, false];
        }

        return ['string', null, false];
    }

    private function extractSchemaFromRegex(\ReflectionClass $reflection, string $className, $context): ?Schema
    {
        $filename = $reflection->getFileName();
        if (!$filename || !file_exists($filename)) return null;

        $contents = file_get_contents($filename);

        // Find toArray method and its return array
        if (!preg_match('/public\s+function\s+toArray\s*\([^)]*\)\s*(?::\s*[^\{]+)?\s*\{([\s\S]*?)return\s+\[([\s\S]*?)\];/m', $contents, $matches)) {
            return null;
        }

        $arrayContent = $matches[2];
        $properties = [];

        // Simplified extraction of keys: 'key' => or "key" =>
        preg_match_all('/[\'"]([\w_]+)[\'"]\s*=>/', $arrayContent, $keyMatches);

        if (empty($keyMatches[1])) return null;

        $uniqueKeys = array_unique($keyMatches[1]);

        foreach ($uniqueKeys as $field) {
            $properties[] = new Property([
                'property' => $field,
                'type'     => 'string', // Default to string as we can't easily know types via regex
                '_context' => $context,
            ]);
        }

        return new Schema([
            'schema'     => $this->getShortName($className),
            'properties' => $properties,
            'type'       => 'object',
            '_context'   => $context,
        ]);
    }

    private function getShortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
