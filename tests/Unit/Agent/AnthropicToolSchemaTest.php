<?php

use App\Agent\AnthropicToolSchema;

it('AGT-003 keeps a capability\'s input schema as the tool schema, minus the dialect keys Anthropic does not take', function () {
    $schema = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        '$id' => 'weigh_in_log.input',
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => ['kg' => ['type' => 'number', 'minimum' => 20]],
        'required' => ['kg'],
    ];

    expect(AnthropicToolSchema::normalise($schema))->toBe([
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => ['kg' => ['type' => 'number', 'minimum' => 20]],
        'required' => ['kg'],
    ]);
});

it('AGT-003 sends empty properties as a JSON object at every depth', function () {
    $normalised = AnthropicToolSchema::normalise([
        'type' => 'object',
        'properties' => ['filters' => ['type' => 'object', 'properties' => []]],
    ]);

    expect(json_encode($normalised))->toBe('{"type":"object","properties":{"filters":{"type":"object","properties":{}}}}')
        ->and(json_encode(AnthropicToolSchema::normalise([])))->toBe('{"type":"object","properties":{}}');
});

it('AGT-003 refuses an input schema that is not an object, which Anthropic cannot take', function () {
    AnthropicToolSchema::normalise(['type' => 'array', 'items' => ['type' => 'string']]);
})->throws(LogicException::class, 'A tool input schema must be a JSON object, not "array".');

it('AGT-003 refuses oneOf, anyOf and allOf at the top of an input schema, which Anthropic cannot take', function (string $keyword) {
    AnthropicToolSchema::normalise(['type' => 'object', $keyword => [['properties' => []]]]);
})->with(['oneOf', 'anyOf', 'allOf'])->throws(LogicException::class, 'at the top of a tool input schema');
