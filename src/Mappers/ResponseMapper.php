<?php

namespace Shekel\SwaggerBridge\Mappers;

use OpenApi\Generator;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\JsonContent;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\Schema;
use OpenApi\Annotations\Items;
use OpenApi\Annotations\Components;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Shekel\SwaggerBridge\Mappers\ResourceMapper;

class ResponseMapper
{
    private array $availableSchemas = [];
    private ResourceMapper $resourceMapper;

    public function __construct()
    {
        $this->resourceMapper = new ResourceMapper();
    }

    public function map($operation, ReflectionMethod $reflection, $analysis)
    {
        // 1. Refresh available schemas
        $this->availableSchemas = [];
        if ($analysis->openapi->components !== Generator::UNDEFINED && $analysis->openapi->components->schemas !== Generator::UNDEFINED) {
            foreach ($analysis->openapi->components->schemas as $schema) {
                $this->availableSchemas[] = $schema->schema;
            }
        }
        foreach ($analysis->annotations as $annotation) {
            if ($annotation instanceof Schema && $annotation->schema !== Generator::UNDEFINED) {
                $this->availableSchemas[] = $annotation->schema;
            }
        }

        $returnType = $reflection->getReturnType();
        
        if (!$returnType) {
            // If no return type is hinted, try to add a generic 200
            $this->addGenericSuccessResponse($operation);
            return;
        }

        $types = [];
        if ($returnType instanceof ReflectionNamedType) {
            $types[] = $returnType;
        } elseif ($returnType instanceof ReflectionUnionType) {
            $types = $returnType->getTypes();
        }

        $hasProcessedSpecificResponse = false;
        foreach ($types as $type) {
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (is_subclass_of($className, JsonResource::class) || $className === AnonymousResourceCollection::class) {
                $this->addResourceResponse($operation, $reflection, $className, $analysis);
                $hasProcessedSpecificResponse = true;
            } elseif (is_subclass_of($className, JsonResponse::class) || $className === JsonResponse::class) {
                $this->addGenericSuccessResponse($operation);
                $hasProcessedSpecificResponse = true;
            }
        }

        // If no specific response was processed from type hints, add a generic one
        if (!$hasProcessedSpecificResponse) {
            $this->addGenericSuccessResponse($operation);
        }
    }

    private function addGenericSuccessResponse($operation)
    {
        // Add default 200 if none exists or if existing 200 has no content
        $exists = false;
        if ($operation->responses !== Generator::UNDEFINED) {
            foreach ($operation->responses as $resp) {
                // Check if there's already a 200 with *some* content
                if ($resp->response == '200' && $resp->content !== Generator::UNDEFINED && !empty((array)$resp->content)) {
                    $exists = true;
                    break;
                }
            }
        }

        if (!$exists) {
            // Remove any existing generic 200 responses before adding a new one
            if ($operation->responses !== Generator::UNDEFINED) {
                $responses = (array)$operation->responses;
                foreach ($responses as $key => $resp) {
                    if ($resp->response == '200' && ($resp->content === Generator::UNDEFINED || empty($resp->content))) {
                        unset($responses[$key]);
                    }
                }
                $operation->responses = array_values($responses);
            }


            $response = new Response([
                'response' => '200',
                'description' => 'Successful operation',
                '_context' => $operation->_context
            ]);

            if ($operation->responses === Generator::UNDEFINED || empty($operation->responses)) {
                $operation->responses = [$response];
            } else {
                $responses = (array)$operation->responses;
                $responses[] = $response;
                $operation->responses = $responses;
            }
        }
    }

    private function addResourceResponse($operation, ReflectionMethod $reflection, string $returnTypeName, $analysis)
    {
        $isCollection = ($returnTypeName === AnonymousResourceCollection::class);
        $fullResourceClass = null;

        // Try to find the actual resource class
        $fullResourceClass = $this->detectResourceClass($operation, $reflection);
        
        $schemaName = 'object';
        if ($fullResourceClass) {
            $schemaName = $this->getShortName($fullResourceClass);
            // If the detection found a collection call, mark it
            if (str_contains($fullResourceClass, '::collection')) { // This part of the logic might be redundant now
                $isCollection = true;
                $fullResourceClass = str_replace('::collection', '', $fullResourceClass);
            }
        } else {
            // Fallback to guessing based on controller method context if not directly returned
            $schemaName = $this->guessResourceName($reflection);
        }

        // AUTO-EXTRACT SCHEMA IF MISSING
        if (!in_array($schemaName, $this->availableSchemas) && $fullResourceClass && class_exists($fullResourceClass)) {
            $newSchema = $this->resourceMapper->extractSchema($fullResourceClass, $analysis->context);
            if ($newSchema) {
                if ($analysis->openapi->components === Generator::UNDEFINED) {
                    $analysis->openapi->components = new Components(['_context' => $analysis->context]);
                }
                if ($analysis->openapi->components->schemas === Generator::UNDEFINED) {
                    $analysis->openapi->components->schemas = [];
                }
                
                $schemas = (array)$analysis->openapi->components->schemas; // Ensure it's an array before pushing
                $schemas[] = $newSchema;
                $analysis->openapi->components->schemas = $schemas;
                
                $this->availableSchemas[] = $schemaName;
            }
        }

        // Remove generic 200 if a specific one is being added
        if (!empty($operation->responses) && $operation->responses !== Generator::UNDEFINED) {
            $responses = (array)$operation->responses;
            foreach ($responses as $key => $resp) {
                if ($resp->response == '200' && ($resp->content === Generator::UNDEFINED || empty($resp->content))) { // Generic 200
                    unset($responses[$key]);
                }
            }
            $operation->responses = array_values($responses);
        }

        $hasSchema = in_array($schemaName, $this->availableSchemas);

        $propertyData = [
            'property' => 'data',
            '_context' => $operation->_context
        ];

        if ($isCollection) {
            $propertyData['type'] = 'array';
            if ($hasSchema) {
                $propertyData['items'] = new Items(['ref' => "#/components/schemas/{$schemaName}", '_context' => $operation->_context]);
            } else {
                $propertyData['items'] = new Items(['type' => 'object', '_context' => $operation->_context]);
            }
        } else {
            if ($hasSchema) {
                $propertyData['ref'] = "#/components/schemas/{$schemaName}";
            } else {
                $propertyData['type'] = 'object';
            }
        }

        $schema = new JsonContent([
            'properties' => [new Property($propertyData)],
            'type' => 'object',
            '_context' => $operation->_context
        ]);

        $response = new Response([
            'response' => '200',
            'description' => 'Successful operation',
            'content' => ['application/json' => $schema],
            '_context' => $operation->_context
        ]);

        if ($operation->responses === Generator::UNDEFINED || empty($operation->responses)) {
            $operation->responses = [$response];
        } else {
            $responses = (array)$operation->responses;
            foreach ($responses as $r) {
                if ($r->response == '200' && $r->content !== Generator::UNDEFINED && !empty((array)$r->content)) return; // Don't add if 200 with content already exists
            }
            $responses[] = $response;
            $operation->responses = $responses;
        }
    }

    private function detectResourceClass($operation, ReflectionMethod $reflection): ?string
    {
        $filename = $reflection->getFileName();
        if (!$filename || !file_exists($filename)) return null;

        $contents = file($filename);
        $methodLines = array_slice($contents, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
        $methodBody = implode('', $methodLines);

        // Get all use statements from the file
        $fileContent = file_get_contents($filename);
        $useStatements = [];
        if (preg_match_all('/use\s+([a-zA-Z0-9_\\\\]+)(?:\s+as\s+([a-zA-Z0-9_]+))?;/', $fileContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fqcn = $match[1];
                $alias = $match[2] ?? $this->getShortName($fqcn); // Get short name if no alias
                
                try {
                    if (class_exists($fqcn)) { // Only add if class actually exists
                        $useStatements[$alias] = $fqcn;
                    }
                } catch (\Throwable $e) {
                    // Ignore classes that cause reflection errors
                }
            }
        }

        $namespace = $reflection->getDeclaringClass()->getNamespaceName();

        // Match: return new SomeResource(...) or return new \App\Http\Resources\SomeResource(...)
        if (preg_match('/return\s+new\s+([a-zA-Z0-9_\\\\]+)/', $methodBody, $matches)) {
            return $this->resolveClassName($matches[1], $namespace, $useStatements);
        }

        // Match: return SomeResource::collection(...) or return \App\Http\Resources\SomeResource::collection(...)
        if (preg_match('/return\s+([a-zA-Z0-9_\\\\]+)::collection/', $methodBody, $matches)) {
            return $this->resolveClassName($matches[1], $namespace, $useStatements);
        }

        // Match: $this->respondWithSuccess([], 'message', 200, \App\Http\Resources\UserResource::class)
        // This is a custom helper that might return a resource
        if (preg_match('/\$this->respondWithSuccess\([^)]*?,\s*([\w\\\\]+)::class\)/', $methodBody, $matches)) {
            return $this->resolveClassName($matches[1], $namespace, $useStatements);
        }

        // Match for AuthController@authenticated and Admin auth guard
        if ($reflection->getDeclaringClass()->getShortName() === 'AuthController' && $reflection->getName() === 'authenticated') {
            // Check for admin guard specifically for AuthController@authenticated
            // This is a heuristic. It's difficult to know the exact guard at this level without running the application.
            // For now, let's assume if it's the admin path, it's AdminResource.
            if (str_contains($operation->path, 'admin/authenticated')) {
                return 'App\\Http\\Resources\\Admins\\AdminResource';
            }
        }


        return null;
    }

    private function resolveClassName(string $name, string $contextNamespace, array $useStatements): string
    {
        // If it's already fully qualified
        if (str_contains($name, '\\') && class_exists($name)) {
            return $name;
        }

        // Check against use statements (aliases or full names)
        if (isset($useStatements[$name])) {
            return $useStatements[$name];
        }

        // Check within the current namespace of the controller
        $candidate = $contextNamespace . '\\' . $name;
        if (class_exists($candidate)) {
            return $candidate;
        }

        // Check common Resource namespaces if not found
        $commonNamespaces = ['App\Http\Resources', 'App\Http\Resources\Consent', 'App\Http\Resources\Admins'];
        foreach ($commonNamespaces as $ns) {
            $candidate = $ns . '\\' . $name;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return $name; // Return as is, it might be a global class or unresolved
    }

    private function guessResourceName(ReflectionMethod $reflection): string
    {
        $methodName = $reflection->getName();
        $controller = $reflection->getDeclaringClass()->getShortName();
        $baseName = str_replace('Controller', '', $controller);
        if ($methodName === 'required') return 'RequiredConsentResource';
        if ($methodName === 'login') return 'UserResource'; // Specific guess for AuthController login
        if ($methodName === 'firebaseAuthentication') return 'UserResource';
        if ($methodName === 'register') return 'UserResource';
        if ($methodName === 'authenticated' && str_contains($controller, 'Admin')) return 'AdminResource'; // Specific guess
        if ($methodName === 'authenticated') return 'UserResource'; // Default for AuthController@authenticated
        if ($methodName === 'serviceLogin') return 'UserResource';
        if ($methodName === 'serviceRefreshExpiredToken') return 'UserResource';
        
        return $baseName . 'Resource';
    }

    private function getShortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
