<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The turns a user's cleared conversations held this month (AGT-005, AGT-020).
 * Clearing deletes the turns, and the day's and month's Coach caps count them,
 * so each is kept here with its token total until the month is over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleared_coach_turns', function (Blueprint $table) {
            $table->id();
            // Cascade: ACC-005.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tokens');
            // When the turn was created, as AgentStatus counts it.
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleared_coach_turns');
    }
};
