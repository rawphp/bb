<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // CAP-019: busy_period_set finds an id another user's busy period holds through this index, not a scan.
        DB::statement("ALTER TABLE `profiles` ADD INDEX `profiles_busy_period_ids` ((CAST(busy_periods->'$[*].id' AS CHAR(26) ARRAY)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `profiles` DROP INDEX `profiles_busy_period_ids`');
    }
};
