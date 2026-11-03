<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly review's answers so far (REV-004, REV-031): one row per user per reviewed week, which review_answer
 * upserts and review_complete applies. Nothing in it changes the plan until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Cascade: ACC-005.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The reviewed week's start.
            $table->date('week_start');
            // A calorie option id: recommendation, repeat_this_week, choose_own, drop_fatigue, cut_calories or add_steps.
            $table->string('option');
            $table->integer('own_kcal')->nullable();
            // REV-011: "Make this your deload week too?"; null for the default.
            $table->boolean('deload_too')->nullable();
            // REV-050.
            $table->boolean('update_step_plan')->default(false);
            // REV-004: the moves that last built, as [{from_index, to_index, kcal}].
            $table->json('moves');
            // Why the last move or option was refused (NUT-022), shown as review_show's preview error.
            $table->text('refusal')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_answers');
    }
};
