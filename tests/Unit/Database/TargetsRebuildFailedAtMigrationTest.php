<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

// SET-004: the migration was renamed inside its unmerged PR so it sorts last. A database that already ran it under the
// earlier name has the column, so running it again under the new name must change nothing rather than fail.
uses(RefreshDatabase::class);

it('SET-004 the targets_rebuild_failed_at migration runs again on a database that already has the column', function () {
    $migration = require database_path('migrations/2026_11_12_000000_add_targets_rebuild_failed_at_to_profiles.php');
    expect(Schema::hasColumn('profiles', 'targets_rebuild_failed_at'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('profiles', 'targets_rebuild_failed_at'))->toBeTrue();
});
