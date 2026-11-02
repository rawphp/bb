<?php

use App\Domain\Progression\WorkoutStatus;
use App\Models\Exercise;
use App\Models\User;
use App\Support\Workout\WorkoutIds;
use App\Support\Workout\WorkoutSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// WO-010, FB-009: a workout write that waited for another reads what that one saved. Under MySQL's REPEATABLE READ a
// plain read keeps the view the transaction's first read took, which a reversible write has taken before it locks;
// WorkoutSnapshot::lockFor() reads with locks, which see the latest commit. The rows are committed through a second
// connection, so this file runs without RefreshDatabase and removes what it made.

afterEach(fn () => Carbon::setTestNow());

it('WO-010 FB-009 a write reads the session as another connection committed it after the write\'s first read', function () {
    Carbon::setTestNow('2026-09-21 18:30:00');
    $default = DB::getDefaultConnection();
    config(['database.connections.racer' => config("database.connections.{$default}")]);
    $racer = DB::connection('racer');
    $exercisesBefore = (int) Exercise::query()->max('id');
    DB::setDefaultConnection('racer');
    try {
        $user = TrainSeed::user();
        $mesocycle = TrainSeed::mesocycle($user);
        $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    } finally {
        DB::setDefaultConnection($default);
    }
    $owner = User::query()->findOrFail($user->id);

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
        $racer->table('users')->where('id', $user->id)->delete();
        $racer->table('exercises')->where('id', '>', $exercisesBefore)->delete();
        DB::purge('racer');
    }

    expect([$plain, $locked])->toBe([WorkoutStatus::InProgress, WorkoutStatus::Finished]);
});

it('WO-010 lockFor() refuses to run outside a transaction, where its locks would end at once', function () {
    expect(fn () => WorkoutSnapshot::lockFor(new User, '01JB6Q3V8T2K9WZ4M5N7P1R3S5'))->toThrow(LogicException::class);
});
