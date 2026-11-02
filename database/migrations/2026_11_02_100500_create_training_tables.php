<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mesocycle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('week');
            // The 1-based position of the day in the mesocycle.
            $table->unsignedTinyInteger('day');
            $table->dateTime('planned_start_at')->nullable();
            $table->dateTime('planned_end_at')->nullable();
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->boolean('is_skipped');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // A ULID key, so the outbox can log a set offline (CAP-019).
        Schema::create('workout_sets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workout_session_id')->constrained()->cascadeOnDelete();
            // Deleting a custom exercise keeps the session's sets.
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type');
            $table->string('variant')->nullable();
            $table->double('target_weight_kg')->nullable();
            $table->unsignedSmallInteger('target_reps')->nullable();
            $table->double('actual_weight_kg')->nullable();
            $table->unsignedSmallInteger('actual_reps')->nullable();
            $table->boolean('is_logged');
            $table->boolean('is_skipped')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'exercise_id']);
        });

        Schema::create('muscle_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workout_session_id')->constrained()->cascadeOnDelete();
            $table->string('muscle_group');
            $table->string('soreness')->nullable();
            $table->string('pump')->nullable();
            $table->string('volume')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('exercise_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->string('joint_pain');
            $table->timestamps();
            $table->softDeletes();
        });

        // EX-010: one pinned note per user per exercise.
        Schema::create('exercise_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();
            $table->unique(['user_id', 'exercise_id']);
        });

        // EX-012: one load step per user per exercise.
        Schema::create('exercise_load_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->double('step_kg');
            $table->timestamps();
            $table->unique(['user_id', 'exercise_id']);
        });

        // SCH-032, NUT-005: an activity outside the training plan.
        Schema::create('other_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('start_time', 5);
            $table->unsignedSmallInteger('minutes');
            $table->string('name', 100);
            $table->string('intensity');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_activities');
        Schema::dropIfExists('exercise_load_steps');
        Schema::dropIfExists('exercise_notes');
        Schema::dropIfExists('exercise_feedback');
        Schema::dropIfExists('muscle_feedback');
        Schema::dropIfExists('workout_sets');
        Schema::dropIfExists('workout_sessions');
    }
};
