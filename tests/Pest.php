<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScreenFixture;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * A read payload matches its fixture in tests/fixtures/screens/<name>.json:
 * the same keys and JSON types at every depth, values free.
 *
 * @param  array<mixed>  $payload
 */
function assertMatchesScreenFixture(string $name, array $payload): void
{
    expect(ScreenFixture::differences(ScreenFixture::load($name), $payload))->toBe([]);
}
