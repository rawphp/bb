<?php

use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\DatabaseSeeder;

it('runs every area seeder in database/seeders by name', function () {
    expect(DatabaseSeeder::areaSeeders())->toContain(AccountSeeder::class)
        ->not->toContain(DatabaseSeeder::class);

    $this->seed();

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

it('seeds the known-password test account only in local and testing', function (string $environment, bool $seeded) {
    app()->instance('env', $environment);

    // Called directly, as `db:seed --force` would: the artisan command's own
    // production prompt is not the guard under test.
    app(AccountSeeder::class)->run();

    expect(User::where('email', 'test@example.com')->exists())->toBe($seeded);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', true],
    'staging' => ['staging', false],
    'production' => ['production', false],
]);
