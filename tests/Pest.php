<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\ScreenFixture;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * A read payload matches its fixture in tests/fixtures/screens/<name>.json:
 * the same keys and JSON types at every depth, values free. It must also
 * validate against tests/fixtures/schemas/<name>.output.json, which decides
 * where null may stand and checks what a null in the fixture leaves unchecked.
 *
 * @param  array<mixed>  $payload
 */
function assertMatchesScreenFixture(string $name, array $payload): void
{
    $fixture = ScreenFixture::load($name);
    $schema = ScreenFixture::outputSchema($name);

    expect(ScreenFixture::differences($fixture, $payload))->toBe([])
        ->and((new JsonSchemaValidator)->validate($schema, $payload))->toBe([], "the payload does not match {$name}.output.json");
}
