<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_weeks', function (Blueprint $table) {
            // SCH-063: the user marked the week rough.
            $table->boolean('is_rough')->default(false)->after('away_calories');
            // PLAN-025: an away week saved with "keep the phase end date" on.
            $table->boolean('keep_phase_end')->default(false)->after('is_rough');
        });
    }

    public function down(): void
    {
        Schema::table('plan_weeks', function (Blueprint $table) {
            $table->dropColumn(['is_rough', 'keep_phase_end']);
        });
    }
};
