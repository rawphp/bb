<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renamed before merge so it sorts last; a database that ran it under its earlier name already has the column,
     * so each step checks first.
     */
    public function up(): void
    {
        if (Schema::hasColumn('profiles', 'targets_rebuild_failed_at')) {
            return;
        }

        Schema::table('profiles', function (Blueprint $table) {
            // SET-004: when a rebuild of the targets failed after the profile saved; null once one runs or is refused.
            $table->timestamp('targets_rebuild_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('profiles', 'targets_rebuild_failed_at')) {
            return;
        }

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('targets_rebuild_failed_at');
        });
    }
};
