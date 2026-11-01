<?php

namespace Tests\Support;

/**
 * Compares a read capability's payload with its screen fixture by shape:
 * the same keys and the same JSON types at every depth. Values are free.
 * Every item of a list is compared with the fixture's first item.
 * Null matches any type, as nullable fields differ between items and states.
 */
final class ScreenFixture
{
    /** @return array<mixed> */
    public static function load(string $name): array
    {
        $path = base_path("tests/fixtures/screens/{$name}.json");

        if (! is_file($path)) {
            throw new \InvalidArgumentException("No screen fixture at tests/fixtures/screens/{$name}.json");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> one line per difference, empty when the shapes match */
    public static function differences(mixed $fixture, mixed $payload, string $path = '$'): array
    {
        $expected = self::type($fixture);
        $actual = self::type($payload);

        // A nullable field holds null on one side and a value on the other.
        // The schema snapshot decides which fields may be null.
        if ($expected === 'null' || $actual === 'null') {
            return [];
        }

        if ($expected !== $actual) {
            return ["{$path}: expected {$expected}, got {$actual}"];
        }

        if ($expected === 'list') {
            if ($fixture === []) {
                return [];
            }

            $differences = [];
            foreach ($payload as $index => $item) {
                array_push($differences, ...self::differences($fixture[0], $item, "{$path}[{$index}]"));
            }

            return $differences;
        }

        if ($expected !== 'object') {
            return [];
        }

        $differences = [];
        foreach (array_diff_key($fixture, $payload) as $key => $_) {
            $differences[] = "{$path}.{$key}: missing from the payload";
        }
        foreach (array_diff_key($payload, $fixture) as $key => $_) {
            $differences[] = "{$path}.{$key}: not in the fixture";
        }
        foreach (array_intersect_key($fixture, $payload) as $key => $value) {
            array_push($differences, ...self::differences($value, $payload[$key], "{$path}.{$key}"));
        }

        return $differences;
    }

    private static function type(mixed $value): string
    {
        return match (true) {
            is_array($value) && $value === [] => 'list',
            is_array($value) => array_is_list($value) ? 'list' : 'object',
            is_int($value), is_float($value) => 'number',
            default => get_debug_type($value),
        };
    }
}
