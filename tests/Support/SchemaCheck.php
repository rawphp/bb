<?php

namespace Tests\Support;

/**
 * Checks a decoded JSON value against the JSON Schema subset that
 * CapabilityData::jsonSchema() writes: type, enum, properties, required,
 * additionalProperties: false, and items.
 */
final class SchemaCheck
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> one line per violation, empty when the value fits
     */
    public static function violations(array $schema, mixed $value, string $path = '$'): array
    {
        $types = (array) ($schema['type'] ?? []);
        $type = self::type($value);

        if ($types !== [] && ! in_array($type, $types, true) && ! ($type === 'integer' && in_array('number', $types, true))) {
            return ["{$path}: expected ".implode('|', $types).", got {$type}"];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum'])];
        }

        $violations = [];

        if ($type === 'object') {
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $violations[] = "{$path}.{$key}: required";
                }
            }
            foreach ($value as $key => $item) {
                if (! array_key_exists($key, $properties)) {
                    $violations[] = "{$path}.{$key}: not in the schema";

                    continue;
                }
                array_push($violations, ...self::violations($properties[$key], $item, "{$path}.{$key}"));
            }
        }

        if ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($violations, ...self::violations($schema['items'], $item, "{$path}[{$index}]"));
            }
        }

        return $violations;
    }

    private static function type(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && ($value === [] || array_is_list($value)) => 'array',
            default => 'object',
        };
    }
}
