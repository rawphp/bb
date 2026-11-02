<?php

namespace Tests\Support;

/**
 * Checks a decoded JSON value against the JSON Schema subset that
 * CapabilityData::jsonSchema() writes: type, enum, properties, required,
 * additionalProperties: false, items, minimum, maximum, minLength,
 * maxLength, minItems, maxItems and the date format.
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

        // A nullable enum lists its values without null (see the PR notes on the package).
        if ($value === null) {
            return [];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum'])];
        }

        $bound = self::boundViolation($schema, $value, $type);
        if ($bound !== null) {
            return ["{$path}: {$bound}"];
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

    /** @param  array<string, mixed>  $schema */
    private static function boundViolation(array $schema, mixed $value, string $type): ?string
    {
        if ($type === 'integer' || $type === 'number') {
            return match (true) {
                isset($schema['minimum']) && $value < $schema['minimum'] => "{$value} is below the minimum {$schema['minimum']}",
                isset($schema['maximum']) && $value > $schema['maximum'] => "{$value} is above the maximum {$schema['maximum']}",
                default => null,
            };
        }

        if ($type === 'string') {
            $length = mb_strlen($value);

            return match (true) {
                isset($schema['minLength']) && $length < $schema['minLength'] => "{$length} characters, under the minLength {$schema['minLength']}",
                isset($schema['maxLength']) && $length > $schema['maxLength'] => "{$length} characters, over the maxLength {$schema['maxLength']}",
                ($schema['format'] ?? null) === 'date' && ! self::isDate($value) => json_encode($value).' is not a date (Y-m-d)',
                default => null,
            };
        }

        if ($type === 'array') {
            $count = count($value);

            return match (true) {
                isset($schema['minItems']) && $count < $schema['minItems'] => "{$count} items, under the minItems {$schema['minItems']}",
                isset($schema['maxItems']) && $count > $schema['maxItems'] => "{$count} items, over the maxItems {$schema['maxItems']}",
                default => null,
            };
        }

        return null;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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
