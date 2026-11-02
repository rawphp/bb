<?php

use App\Actions\Workout\FinishWorkout;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Progression\WorkoutStatus;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\WorkoutIds;
use App\Support\Workout\WorkoutSnapshot;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// WO-010, FB-009: a workout write that waited for another reads what that one saved. Under MySQL's REPEATABLE READ a
// plain read keeps the view the transaction's first read took, which a reversible write has taken before it locks;
// WorkoutSnapshot::lockFor() reads with locks, which see the latest commit. The rows are committed through a second
// connection, so this file runs without RefreshDatabase and removes what it made.

afterEach(fn () => Carbon::setTestNow());

/**
 * The user, Meso 2 and week 3's started Upper A, committed through the second connection.
 *
 * @return array{Connection, User, Mesocycle, WorkoutSession, int}
 */
function committedSession(): array
{
    Carbon::setTestNow('2026-09-21 18:30:00');
    $default = DB::getDefaultConnection();
    config(['database.connections.racer' => config("database.connections.{$default}")]);
    $racer = DB::connection('racer');
    $exercisesBefore = (int) Exercise::query()->max('id');
    DB::setDefaultConnection('racer');
    try {
        $user = TrainSeed::user();
        $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
        $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    } finally {
        DB::setDefaultConnection($default);
    }

    return [$racer, User::query()->findOrFail($user->id), $mesocycle, $session, $exercisesBefore];
}

function removeCommitted(Connection $racer, User $user, int $exercisesBefore): void
{
    $racer->table('users')->where('id', $user->id)->delete();
    $racer->table('exercises')->where('id', '>', $exercisesBefore)->delete();
    DB::purge('racer');
}

it('WO-010 FB-009 a write reads the session as another connection committed it after the write\'s first read', function () {
    [$racer, $owner, $mesocycle, $session, $exercisesBefore] = committedSession();

    try {
        [$plain, $locked] = DB::transaction(function () use ($racer, $owner, $mesocycle, $session): array {
            DB::table('users')->count();
            $racer->table('workout_sessions')->where('id', $session->id)->update(['actual_end_at' => '2026-09-21 18:25:00']);

            return [
                WorkoutSnapshot::load($owner, $mesocycle->id, 3, 1)->status(),
                WorkoutSnapshot::lockFor($owner, WorkoutIds::workout($mesocycle->id, 3, 1))->status(),
            ];
        });
    } finally {
        removeCommitted($racer, $owner, $exercisesBefore);
    }

    expect([$plain, $locked])->toBe([WorkoutStatus::InProgress, WorkoutStatus::Finished]);
});

it('FB-009 PROG-019 a write reads the answers and next week\'s counts another connection committed after its first read', function () {
    [$racer, $owner, $mesocycle, $session, $exercisesBefore] = committedSession();
    $bench = WorkoutSeed::exerciseId($mesocycle, 1);
    $days = $mesocycle->days;
    $days[0]['slots'][0]['sets'] = 4;

    try {
        [$plain, $locked] = DB::transaction(function () use ($racer, $owner, $mesocycle, $session, $bench, $days): array {
            DB::table('users')->count();
            $racer->table('muscle_feedback')->insert(['id' => (string) Str::uuid7(), 'user_id' => $owner->id, 'workout_session_id' => $session->id,
                'muscle_group' => 'chest', 'pump' => 'low', 'volume' => 'notEnough', 'created_at' => '2026-09-21 18:20:00', 'updated_at' => '2026-09-21 18:20:00']);
            $racer->table('exercise_feedback')->insert(['id' => (string) Str::uuid7(), 'user_id' => $owner->id, 'workout_session_id' => $session->id,
                'exercise_id' => $bench, 'joint_pain' => 'moderate', 'created_at' => '2026-09-21 18:20:00', 'updated_at' => '2026-09-21 18:20:00']);
            $racer->table('mesocycles')->where('id', $mesocycle->id)->update(['days' => json_encode($days)]);
            $read = fn (WorkoutSnapshot $s): array => [
                $s->muscleAnswers(MuscleGroup::Chest)?->pump,
                $s->jointAnswers()[$bench] ?? null,
                $s->mesocycle->dayRows()[0]['slots'][0]['sets'],
            ];

            return [
                $read(WorkoutSnapshot::load($owner, $mesocycle->id, 3, 1)),
                $read(WorkoutSnapshot::lockFor($owner, WorkoutIds::workout($mesocycle->id, 3, 1))),
            ];
        });
    } finally {
        removeCommitted($racer, $owner, $exercisesBefore);
    }

    expect($plain)->toBe([null, null, 3])
        ->and($locked)->toBe(['low', JointPain::Moderate, 4]);
});

it('CAP-013 a finish marks the answers another connection committed while it waited', function () {
    [$racer, $owner, $mesocycle, $session, $exercisesBefore] = committedSession();
    $bench = WorkoutSeed::exerciseId($mesocycle, 1);

    try {
        $stamps = DB::transaction(function () use ($racer, $owner, $mesocycle, $session, $bench): array {
            DB::table('users')->count();
            $racer->table('muscle_feedback')->insert(['id' => (string) Str::uuid7(), 'user_id' => $owner->id, 'workout_session_id' => $session->id,
                'muscle_group' => 'chest', 'pump' => 'moderate', 'volume' => 'justRight', 'created_at' => '2026-09-21 18:20:00', 'updated_at' => '2026-09-21 18:20:00']);
            $racer->table('exercise_feedback')->insert(['id' => (string) Str::uuid7(), 'user_id' => $owner->id, 'workout_session_id' => $session->id,
                'exercise_id' => $bench, 'joint_pain' => 'none', 'created_at' => '2026-09-21 18:20:00', 'updated_at' => '2026-09-21 18:20:00']);

            app(FinishWorkout::class)($owner, WorkoutIds::workout($mesocycle->id, 3, 1), Carbon::now());

            return [
                ...DB::table('muscle_feedback')->where('workout_session_id', $session->id)->lockForUpdate()->pluck('updated_at')->all(),
                ...DB::table('exercise_feedback')->where('workout_session_id', $session->id)->lockForUpdate()->pluck('updated_at')->all(),
            ];
        });
    } finally {
        removeCommitted($racer, $owner, $exercisesBefore);
    }

    expect($stamps)->toBe(['2026-09-21 18:30:00', '2026-09-21 18:30:00']);
});

it('WO-010 lockFor() refuses to run outside a transaction, where its locks would end at once', function () {
    expect(fn () => WorkoutSnapshot::lockFor(new User, '01JB6Q3V8T2K9WZ4M5N7P1R3S5'))->toThrow(LogicException::class);
});
