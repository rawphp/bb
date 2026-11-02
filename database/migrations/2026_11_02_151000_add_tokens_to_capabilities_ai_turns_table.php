<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

/**
 * Each turn's LLM token total, written by App\Agent\MeteredTurnClaim, so the
 * AGT-005 monthly cap is one SUM. The agent tables are new in the same release,
 * so there is nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(TableNames::turns(), function (Blueprint $table): void {
            $table->unsignedBigInteger('tokens')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table(TableNames::turns(), function (Blueprint $table): void {
            $table->dropColumn('tokens');
        });
    }
};
