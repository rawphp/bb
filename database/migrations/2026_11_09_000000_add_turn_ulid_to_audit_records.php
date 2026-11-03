<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_records', function (Blueprint $table) {
            // AGT-013: the Coach turn an agent write was made in; null for every other channel.
            $table->string('turn_ulid', 26)->nullable()->after('channel');
            $table->index(['turn_ulid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_records', function (Blueprint $table) {
            $table->dropIndex(['turn_ulid', 'created_at']);
            $table->dropColumn('turn_ulid');
        });
    }
};
