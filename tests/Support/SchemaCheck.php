<?php

namespace Tests\Support;

/**
 * Checks a decoded JSON value against a schema snapshot. It covers the subset
 * CapabilityData::jsonSchema() emits: type (one or a list), properties,
 * required, additionalProperties false, items, enum, minimum and maximum.
 */
final class SchemaCheck
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> one line per problem, empty when the value fits
     */
    public static function problems(array $schema, mixed $value, string $path = '$'): array
    {
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! in_array(self::type($value, $types), $types, true)) {
            return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($value)];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum'])];
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return ["{$path}: {$value} is below {$schema['minimum']}"];
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return ["{$path}: {$value} is above {$schema['maximum']}"];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $problems = [];

        if (array_is_list($value) && in_array('array', $types, true)) {
            foreach ($value as $index => $item) {
                array_push($problems, ...self::problems($schema['items'] ?? [], $item, "{$path}[{$index}]"));
            }

            return $problems;
        }

        $properties = $schema['properties'] ?? [];
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $problems[] = "{$path}.{$key}: required";
            }
        }
        foreach ($value as $key => $item) {
            if (! array_key_exists($key, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $problems[] = "{$path}.{$key}: not in the schema";
                }

                continue;
            }
            array_push($problems, ...self::problems($properties[$key], $item, "{$path}.{$key}"));
        }

        return $problems;
    }

    /** @param  list<string>  $allowed */
    private static function type(mixed $value, array $allowed): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => in_array('integer', $allowed, true) ? 'integer' : 'number',
            is_float($value) => 'number',
            is_string($value) => 'string',
            // [] decodes the same for an empty list and an empty object.
            is_array($value) && $value === [] => in_array('array', $allowed, true) ? 'array' : 'object',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => get_debug_type($value),
        };
    }
}
