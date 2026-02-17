<?php

namespace Shekel\SwaggerBridge\Mappers;

use OpenApi\Generator;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\RequestBody;
use OpenApi\Annotations\JsonContent;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\Items;
use OpenApi\Annotations\Schema;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Parameter;
use Illuminate\Foundation\Http\FormRequest;

class RequestMapper
{
    public function map($operation, string $className)
    {
        if (!class_exists($className)) return;
        
        $request = new $className();
        $method = strtolower($operation->method);

        // 1. Process standard rules()
        if (method_exists($request, 'rules')) {
            try {
                $rules = $request->rules();
                if (is_array($rules)) {
                    if (in_array($method, ['get', 'delete'])) {
                        $this->mapToQueryParameters($operation, $rules);
                    } else {
                        $this->mapToRequestBody($operation, $rules);
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 2. Process dedicated queryRules() if they exist (allows Query Params in POST/PUT)
        if (method_exists($request, 'queryRules')) {
            try {
                $queryRules = $request->queryRules();
                if (is_array($queryRules)) {
                    $this->mapToQueryParameters($operation, $queryRules);
                }
            } catch (\Throwable $e) {}
        }

        // Add 422 response automatically
        $this->addErrorResponse($operation);
    }

    private function mapToQueryParameters($operation, array $rules)
    {
        $parameters = is_array($operation->parameters) ? $operation->parameters : [];

        foreach ($rules as $field => $ruleSet) {
            // Avoid duplicating existing parameters (path or already added query)
            $exists = false;
            foreach ($parameters as $p) {
                if ($p instanceof Parameter && $p->name === $field) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;

            $ruleArray = is_string($ruleSet) ? explode('|', $ruleSet) : (is_array($ruleSet) ? $ruleSet : []);
            
            $type = $this->guessType($ruleArray);
            
            $schemaData = [
                'type' => $type,
                '_context' => $operation->_context
            ];

            if ($type === 'array') {
                $schemaData['items'] = new Items(['type' => 'string', '_context' => $operation->_context]);
            }

            $schema = new Schema($schemaData);

            if ($enum = $this->getEnum($ruleArray)) {
                $schema->enum = $enum;
            }

            $parameters[] = new Parameter([
                'name' => $field,
                'in' => 'query',
                'required' => in_array('required', $ruleArray),
                'schema' => $schema,
                '_context' => $operation->_context
            ]);
        }

        $operation->parameters = $parameters;
    }

    private function mapToRequestBody($operation, array $rules)
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $ruleSet) {
            $ruleArray = is_string($ruleSet) ? explode('|', $ruleSet) : (is_array($ruleSet) ? $ruleSet : []);
            
            $type = $this->guessType($ruleArray);
            
            $propertyData = [
                'property' => $field,
                'type' => $type,
                '_context' => $operation->_context
            ];

            if ($type === 'array') {
                $propertyData['items'] = new Items(['type' => 'string', '_context' => $operation->_context]);
            }

            $property = new Property($propertyData);

            if (in_array('required', $ruleArray)) {
                $required[] = $field;
            }

            if ($enum = $this->getEnum($ruleArray)) {
                $property->enum = $enum;
            }

            if ($format = $this->getFormat($ruleArray)) {
                $property->format = $format;
            }

            $properties[] = $property;
        }

        $operation->requestBody = new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => new JsonContent([
                    'properties' => $properties,
                    'required' => !empty($required) ? $required : null,
                    '_context' => $operation->_context
                ])
            ],
            '_context' => $operation->_context
        ]);
    }

    private function guessType(array $rules): string
    {
        if (in_array('integer', $rules) || in_array('numeric', $rules)) return 'integer';
        if (in_array('boolean', $rules)) return 'boolean';
        if (in_array('array', $rules)) return 'array';
        return 'string';
    }

    private function getEnum(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3));
            }
        }
        return null;
    }

    private function getFormat(array $rules): ?string
    {
        if (in_array('email', $rules)) return 'email';
        if (in_array('uuid', $rules)) return 'uuid';
        return null;
    }

    private function addErrorResponse($operation)
    {
        $response422 = new Response([
            'response' => '422',
            'description' => 'Unprocessable Entity (Validation Error)',
            '_context' => $operation->_context
        ]);

        if ($operation->responses === Generator::UNDEFINED || empty($operation->responses)) {
            $operation->responses = [$response422];
        } else {
            $responses = (array)$operation->responses;
            foreach ($responses as $r) {
                if ($r instanceof Response && $r->response == '422') return;
            }
            $responses[] = $response422;
            $operation->responses = $responses;
        }
    }
}
