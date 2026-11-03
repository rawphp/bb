<?php

use App\Actions\Today\ResolveMissedWeek;
use App\Capabilities\Today\Data\MissedWeekResolveInput;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrainSeed;

// SCH-058: a restart waits for whatever else holds the user's plan row. The rows are committed through a second
// connection (as LockForTest does), so this file runs without RefreshDatabase and removes what it made.

afterEach(fn () => Carbon::setTestNow());

it('SCH-058 a restart takes the plan row lock, so it waits for a write that holds it', function () {
    Carbon::setTestNow('2026-09-29 08:00:00');
    $default = DB::getDefaultConnection();
    config(['database.connections.racer' => config("database.connections.{$default}")]);
    $racer = DB::connection('racer');
    $exercisesBefore = (int) DB::table('exercises')->max('id');
    DB::setDefaultConnection('racer');
    try {
        $user = TrainSeed::user();
        $mesocycle = TrainSeed::mesocycle($user);
    } finally {
        DB::setDefaultConnection($default);
    }
    $user = User::query()->findOrFail($user->id);

    $racer->beginTransaction();
    try {
        $racer->table('plans')->where('user_id', $user->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        expect(fn () => app(ResolveMissedWeek::class)($user, new MissedWeekResolveInput($mesocycle->id, 'restart')))
            ->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        $racer->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $racer->table('users')->where('id', $user->id)->delete();
        $racer->table('exercises')->where('id', '>', $exercisesBefore)->delete();
        DB::purge('racer');
    }

    expect(Mesocycle::query()->whereKey($mesocycle->id)->exists())->toBeFalse();
});
