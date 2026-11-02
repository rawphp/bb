<?php

namespace App\Agent;

use LogicException;
use stdClass;

/**
 * A capability's input schema, adjusted at the tool catalog boundary for what
 * Anthropic's `input_schema` takes (AGT-003): an object at the top with no
 * oneOf, anyOf or allOf there, no `$schema` or `$id`, and `properties` sent as
 * a JSON object even when empty.
 */
final class AnthropicToolSchema
{
    private const DIALECT_KEYS = ['$schema', '$id'];

    private const TOP_LEVEL_COMBINATORS = ['oneOf', 'anyOf', 'allOf'];

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalise(array $schema): array
    {
        $type = $schema['type'] ?? 'object';
        if ($type !== 'object') {
            throw new LogicException(sprintf('A tool input schema must be a JSON object, not "%s".', is_string($type) ? $type : json_encode($type)));
        }

        foreach (self::TOP_LEVEL_COMBINATORS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw new LogicException("Anthropic does not take {$keyword} at the top of a tool input schema.");
            }
        }

        $schema = array_diff_key($schema, array_flip(self::DIALECT_KEYS));

        return ['type' => 'object'] + self::objectProperties($schema) + ['properties' => new stdClass];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function objectProperties(array $node): array
    {
        foreach ($node as $key => $value) {
            if ($key === 'properties' && $value === []) {
                $node[$key] = new stdClass;
            } elseif (is_array($value)) {
                $node[$key] = self::objectProperties($value);
            }
        }

        return $node;
    }
}
