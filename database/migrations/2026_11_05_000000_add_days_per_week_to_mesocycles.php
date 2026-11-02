<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesocycles', function (Blueprint $table) {
            // MESO-020, MESO-022: the days per week a build lays out its days from; null until the user picks them.
            $table->unsignedTinyInteger('days_per_week')->nullable()->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('mesocycles', function (Blueprint $table) {
            $table->dropColumn('days_per_week');
        });
    }
};
