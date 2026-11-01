<?php

namespace Tests\Support;

/**
 * Checks a JSON value against the subset of JSON Schema that CapabilityData
 * emits: type (one or a list), properties, required, additionalProperties,
 * items and enum. Contract fixtures use it to stay true to their DTOs.
 */
final class SchemaShape
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> one line per problem, empty when the value fits
     */
    public static function errors(array $schema, mixed $value, string $path = '$'): array
    {
        $types = (array) ($schema['type'] ?? []);
        $actual = self::type($value);

        if ($types !== [] && ! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
            return ["{$path}: expected ".implode('|', $types).", got {$actual}"];
        }

        if ($value !== null && isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
            return ["{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum'])];
        }

        $errors = [];

        if ($actual === 'object' && is_array($value)) {
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key}: missing";
                }
            }
            foreach ($value as $key => $item) {
                if (isset($properties[$key])) {
                    array_push($errors, ...self::errors($properties[$key], $item, "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key}: not in the schema";
                }
            }
        }

        if ($actual === 'array' && is_array($value) && isset($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            foreach ($value as $index => $item) {
                array_push($errors, ...self::errors($items, $item, "{$path}[{$index}]"));
            }
        }

        return $errors;
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
