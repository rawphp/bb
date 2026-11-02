<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesocycles', function (Blueprint $table) {
            // MESO-002, MESO-007: the route a draft is being built on: template, copy_previous, builder or scratch.
            $table->string('route')->nullable()->after('is_resensitization');
            // MESO-064: the user took the recommended active rest, so the next mesocycle starts a week later.
            $table->boolean('active_rest')->default(false)->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('mesocycles', function (Blueprint $table) {
            $table->dropColumn(['route', 'active_rest']);
        });
    }
};
