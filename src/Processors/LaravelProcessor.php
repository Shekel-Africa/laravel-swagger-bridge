<?php

namespace Shekel\SwaggerBridge\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations\Get;
use OpenApi\Annotations\Post;
use OpenApi\Annotations\Put;
use OpenApi\Annotations\Delete;
use OpenApi\Annotations\Patch;
use OpenApi\Annotations\PathItem;
use OpenApi\Annotations\RequestBody;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Schema;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\JsonContent;
use OpenApi\Generator;
use OpenApi\Processors\ProcessorInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Shekel\SwaggerBridge\Mappers\RequestMapper;
use Shekel\SwaggerBridge\Mappers\ResponseMapper;

class LaravelProcessor implements ProcessorInterface
{
    private $requestMapper;
    private $responseMapper;

    public function __construct()
    {
        $this->requestMapper = new RequestMapper();
        $this->responseMapper = new ResponseMapper();
    }

    public function __invoke(Analysis $analysis)
    {
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            $action = $route->getAction();
            $uses = $action['uses'] ?? null;

            if (!$uses || !is_string($uses) || !str_contains($uses, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $uses);

            if (!$controller || !$method || $controller === 'Closure') {
                continue;
            }

            // Generate for all routes starting with 'api/'
            if (!str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $this->processRoute($analysis, $route, $controller, $method);
        }
    }

    private function processRoute(Analysis $analysis, $route, $controller, $method)
    {
        $uri = '/' . ltrim($route->uri(), '/');
        
        $pathItem = null;
        foreach ($analysis->annotations as $annotation) {
            if ($annotation instanceof PathItem && $annotation->path === $uri) {
                $pathItem = $annotation;
                break;
            }
        }

        if (!$pathItem) {
            $pathItem = new PathItem(['path' => $uri, '_context' => $analysis->context]);
            $analysis->addAnnotation($pathItem, $analysis->context);
            
            if ($analysis->openapi->paths === Generator::UNDEFINED) {
                $analysis->openapi->paths = [];
            }
            $paths = (array)$analysis->openapi->paths;
            $paths[] = $pathItem;
            $analysis->openapi->paths = $paths;
        }

        $methods = $route->methods();
        foreach ($methods as $httpMethod) {
            $httpMethod = strtolower($httpMethod);
            if ($httpMethod === 'head' || $httpMethod === 'options') continue;

            if (isset($pathItem->{$httpMethod}) && $pathItem->{$httpMethod} !== Generator::UNDEFINED) {
                continue;
            }

            $operationClass = match ($httpMethod) {
                'get' => Get::class,
                'post' => Post::class,
                'put' => Put::class,
                'delete' => Delete::class,
                'patch' => Patch::class,
                default => null
            };

            if (!$operationClass) continue;

            $operation = new $operationClass([
                'path' => $uri,
                'tags' => [$this->getTagName($controller)],
                '_context' => $analysis->context
            ]);

            $this->addMetadataToOperation($operation, $controller, $method, $route, $analysis);
            
            // ENSURE AT LEAST ONE RESPONSE
            if ($operation->responses === Generator::UNDEFINED || empty($operation->responses)) {
                $operation->responses = [
                    new Response([
                        'response' => '200',
                        'description' => 'Successful operation',
                        '_context' => $operation->_context
                    ])
                ];
            }

            $pathItem->{$httpMethod} = $operation;
            $analysis->addAnnotation($operation, $analysis->context);
        }
    }

    private function getTagName($controller)
    {
        $parts = explode('\\', $controller);
        $name = end($parts);
        return str_replace('Controller', '', $name);
    }

    private function addMetadataToOperation($operation, $controller, $method, $route, Analysis $analysis)
    {
        if (!class_exists($controller) || !method_exists($controller, $method)) {
            return;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (\ReflectionException $e) {
            return;
        }

        // 0. Extract DocBlock info
        $docComment = $reflection->getDocComment();
        if ($docComment) {
            $this->processDocBlock($operation, $docComment);
        }

        // 1. Process Route Parameters
        $this->processPathParameters($operation, $route, $reflection);

        // 2. Process FormRequest (RequestBody)
        foreach ($reflection->getParameters() as $parameter) {
            $paramType = $parameter->getType();
            if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
                $className = $paramType->getName();
                if (class_exists($className) && is_subclass_of($className, FormRequest::class)) {
                    $this->requestMapper->map($operation, $className);
                }
            }
        }

        $this->responseMapper->map($operation, $reflection, $analysis);

        // Set Operation ID
        $routeName = $route->getName();
        $cleanPath = str_replace(['{', '}', '/'], ['', '', '.'], trim($route->uri(), '/'));
        $httpMethod = strtolower($operation->method);

        if (!empty($routeName)) {
            // Check if this route name was already used (for a different path)
            // If it was, append the path to make it unique
            $operation->operationId = $routeName . '.' . $httpMethod . '.' . substr(md5($route->uri()), 0, 4);
        } else {
            // Generate readable unique ID: api.v3.path.method
            $operation->operationId = $cleanPath . '.' . $httpMethod;
        }

        $this->processMiddleware($operation, $route);
    }

    private function processDocBlock($operation, string $docComment)
    {
        $lines = explode("\n", $docComment);
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = trim($line, "/* \t\r\n");
            if (!empty($line) && !str_starts_with($line, '@')) {
                $cleanLines[] = $line;
            }
        }

        if (!empty($cleanLines)) {
            $operation->summary = $cleanLines[0];
            if (count($cleanLines) > 1) {
                $operation->description = implode(' ', array_slice($cleanLines, 1));
            }
        }
    }

    private function processPathParameters($operation, $route, ReflectionMethod $reflection)
    {
        preg_match_all('/\{(\w+)\}/', $route->uri(), $matches);
        if (empty($matches[1])) {
            return;
        }

        $params = [];
        $wheres = $route->wheres;

        foreach ($matches[1] as $paramName) {
            $type = 'string';
            $format = null;

            foreach ($reflection->getParameters() as $refParam) {
                if ($refParam->getName() === $paramName) {
                    $refType = $refParam->getType();
                    if ($refType instanceof ReflectionNamedType) {
                        $typeName = $refType->getName();
                        if ($typeName === 'int') $type = 'integer';
                        elseif ($typeName === 'bool') $type = 'boolean';
                        elseif (class_exists($typeName) && is_subclass_of($typeName, Model::class)) {
                            $type = 'string';
                            $format = 'uuid';
                        }
                    }
                    break;
                }
            }

            if ($type === 'string' && isset($wheres[$paramName])) {
                $constraint = $wheres[$paramName];
                if ($constraint === '[0-9]+' || $constraint === '[0-9]*') {
                    $type = 'integer';
                }
            }

            $params[] = new Parameter([
                'name' => $paramName,
                'in' => 'path',
                'required' => true,
                'schema' => new Schema([
                    'type' => $type,
                    'format' => $format,
                    '_context' => $operation->_context
                ]),
                '_context' => $operation->_context
            ]);
        }
        
        $operation->parameters = $params;
    }

    private function processMiddleware($operation, $route)
    {
        $middlewares = $route->gatherMiddleware();
        $isAuth = false;
        
        foreach ($middlewares as $middleware) {
            if (str_contains($middleware, 'auth') || str_contains($middleware, 'client.auth')) {
                $isAuth = true;
                break;
            }
        }

        if ($isAuth) {
            $operation->security = [['bearerAuth' => []]];
            
            $authResponses = [
                new Response([
                    'response' => '401',
                    'description' => 'Unauthenticated',
                    '_context' => $operation->_context
                ]),
                new Response([
                    'response' => '403',
                    'description' => 'Unauthorized/Forbidden',
                    '_context' => $operation->_context
                ])
            ];

            if ($operation->responses === Generator::UNDEFINED || empty($operation->responses)) {
                $operation->responses = $authResponses;
            } else {
                $operation->responses = array_merge((array)$operation->responses, $authResponses);
            }
        }
    }
}
