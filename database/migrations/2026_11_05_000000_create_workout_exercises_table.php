<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WO-002, WO-022: an exercise of a started session, in its order, with this session's note. A ULID key that
        // workout_show sends before the session starts (OFF-021), so the outbox can name it.
        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workout_session_id')->constrained()->cascadeOnDelete();
            // Deleting a custom exercise keeps the session's exercises, as it keeps their sets.
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Every set belongs to one exercise of its session; no set rows existed before this.
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->foreignUlid('workout_exercise_id')->after('workout_session_id')
                ->constrained('workout_exercises')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workout_exercise_id');
        });
        Schema::dropIfExists('workout_exercises');
    }
};
