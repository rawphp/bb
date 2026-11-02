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
