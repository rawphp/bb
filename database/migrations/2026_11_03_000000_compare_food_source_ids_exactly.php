<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * foods.source_id is a dataset's own code (an fdcId, an Open Food Facts barcode), so the unique
 * (source, source_id) index must compare it exactly. MySQL's default collation ignores case and
 * accents, which merged distinct codes such as "ABC1" and "abc1", or "STRASSE1" and "STRAßE1",
 * into one food. A binary collation keeps them apart. Other drivers compare strings exactly already.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    /**
     * Back to the folding collation. This fails on the unique index while two foods' source ids differ
     * only by case or accent, which the binary collation allows: restore the backup taken before the
     * import instead (docs/self-hosting.md, "Food data").
     */
    public function down(): void
    {
        $this->collate((string) config('database.connections.mysql.collation', 'utf8mb4_unicode_ci'));
    }

    private function collate(string $collation): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('foods', function (Blueprint $table) use ($collation): void {
            $table->string('source_id', 64)->collation($collation)->change();
        });
    }
};
