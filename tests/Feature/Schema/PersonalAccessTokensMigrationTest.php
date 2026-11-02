<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('CAP-019 rebuilds a token table that Sanctum\'s shipped migration made, so a token takes its ULID and scope', function () {
    $migration = require database_path('migrations/2026_11_02_200000_rebuild_personal_access_tokens_table.php');
    // DDL commits on MySQL, so this test rebuilds the database when it is done.
    try {
        // The table as an install that ran the shipped migration has it.
        $migration->down();
        expect(Schema::getColumnType('personal_access_tokens', 'id'))->toBe('bigint')
            ->and(Schema::hasColumn('personal_access_tokens', 'scope'))->toBeFalse();

        $migration->up();

        $token = PersonalAccessToken::factory()->for(User::factory(), 'tokenable')->create(['id' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8']);
        expect(Schema::getColumnType('personal_access_tokens', 'id'))->toBe('char')
            ->and(Schema::hasColumn('personal_access_tokens', 'scope'))->toBeTrue()
            ->and(PersonalAccessToken::query()->findOrFail('01JBX8Z6Q7M0V2K3N4P5R6S7T8')->is($token))->toBeTrue();
    } finally {
        $this->warpRecycleDatabase();
    }
});
