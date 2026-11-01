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
