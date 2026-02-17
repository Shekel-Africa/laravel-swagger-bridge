<?php

namespace Shekel\SwaggerBridge\Mappers;

use OpenApi\Annotations\Schema;
use OpenApi\Annotations\Property;
use OpenApi\Generator;

class ResourceMapper
{
    public function extractSchema(string $className, $context): ?Schema
    {
        if (!class_exists($className)) return null;

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return null;
        }
        
        $filename = $reflection->getFileName();
        if (!$filename || !file_exists($filename)) return null;

        $contents = file_get_contents($filename);
        
        // Find toArray method and its return array
        // This is a simplified regex that looks for return [ ... ];
        if (!preg_match('/public\s+function\s+toArray\s*\([^)]*\)\s*(?::\s*[^\{]+)?\s*\{([\s\S]*?)return\s+\[([\s\S]*?)\];/m', $contents, $matches)) {
            return null;
        }

        $arrayContent = $matches[2];
        $properties = [];

        // Simplified extraction of keys: 'key' => or "key" =>
        // We handle basic string keys.
        preg_match_all('/[\'"]([\w_]+)[\'"]\s*=>/', $arrayContent, $keyMatches);

        if (empty($keyMatches[1])) return null;

        $uniqueKeys = array_unique($keyMatches[1]);

        foreach ($uniqueKeys as $field) {
            $properties[] = new Property([
                'property' => $field,
                'type' => 'string', // Default to string as we can't easily know types via regex
                '_context' => $context
            ]);
        }

        return new Schema([
            'schema' => $this->getShortName($className),
            'properties' => $properties,
            'type' => 'object',
            '_context' => $context
        ]);
    }

    private function getShortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
