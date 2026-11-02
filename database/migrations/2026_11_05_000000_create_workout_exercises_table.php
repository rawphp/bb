<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        // Every set belongs to one exercise of its session. Sets that exist already get one, then the column tightens.
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->ulid('workout_exercise_id')->nullable()->after('workout_session_id');
        });
        $this->backfill();
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->ulid('workout_exercise_id')->nullable(false)->change();
            $table->foreign('workout_exercise_id')->references('id')->on('workout_exercises')->cascadeOnDelete();
        });
    }

    /**
     * One exercise per session and library exercise, for every set row (deleted ones too), ordered by the first set's
     * position: how workout_show grouped sets before blocks were stored.
     */
    private function backfill(): void
    {
        $groups = DB::table('workout_sets')
            ->select('workout_session_id', 'exercise_id', 'user_id', DB::raw('min(position) as first'))
            ->groupBy('workout_session_id', 'exercise_id', 'user_id')
            ->orderBy('workout_session_id')->orderBy('first')
            ->get();

        $positions = [];
        foreach ($groups as $group) {
            $id = strtolower((string) Str::ulid());
            $position = $positions[$group->workout_session_id] = ($positions[$group->workout_session_id] ?? 0) + 1;
            DB::table('workout_exercises')->insert([
                'id' => $id, 'user_id' => $group->user_id, 'workout_session_id' => $group->workout_session_id,
                'exercise_id' => $group->exercise_id, 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('workout_sets')->where('workout_session_id', $group->workout_session_id)
                ->where('user_id', $group->user_id)
                ->when($group->exercise_id === null, fn ($query) => $query->whereNull('exercise_id'), fn ($query) => $query->where('exercise_id', $group->exercise_id))
                ->update(['workout_exercise_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workout_exercise_id');
        });
        Schema::dropIfExists('workout_exercises');
    }
};
