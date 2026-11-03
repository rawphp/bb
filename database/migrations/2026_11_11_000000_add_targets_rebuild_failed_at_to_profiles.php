<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // SET-004: when a rebuild of the targets failed after the profile saved; null once one runs or is refused.
            $table->timestamp('targets_rebuild_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('targets_rebuild_failed_at');
        });
    }
};
