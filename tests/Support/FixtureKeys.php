<?php

namespace Tests\Support;

/**
 * Lists the object keys a payload leaves out of its JSON Schema, at every
 * depth. The package's DTO schemas mark nullable keys optional, so schema
 * validation alone would pass a fixture that drops one; a read always sends
 * every key.
 */
final class FixtureKeys
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function missing(array $schema, mixed $data, string $path = '$'): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (isset($schema['items']) && is_array($schema['items']) && array_is_list($data)) {
            $missing = [];
            foreach ($data as $index => $item) {
                array_push($missing, ...self::missing($schema['items'], $item, "{$path}[{$index}]"));
            }

            return $missing;
        }

        $missing = [];
        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (! array_key_exists($key, $data)) {
                $missing[] = "{$path}.{$key}";

                continue;
            }
            array_push($missing, ...self::missing($property, $data[$key], "{$path}.{$key}"));
        }

        return $missing;
    }
}
