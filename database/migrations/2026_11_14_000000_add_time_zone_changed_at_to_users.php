<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SET-012: when the stored time zone last changed, so a change gives the Coach no fresh daily allowance (AGT-005).
            $table->timestamp('time_zone_changed_at')->nullable()->after('time_zone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('time_zone_changed_at');
        });
    }
};
