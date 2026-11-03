<?php

use App\Actions\Plan\RebuildTargets;
use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// SET-004: the rebuild takes the plan row lock, so it waits for whatever else holds it and writes nothing meanwhile. The rows are committed through a second connection (as PlanWeekWriteLockTest
// does), so this file runs without RefreshDatabase and removes what it made. Today is Wednesday 2026-09-23.

afterEach(fn () => Carbon::setTestNow());

it('SET-004 the rebuild takes the plan row lock, so it waits for a write that holds it', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $default = DB::getDefaultConnection();
    config(['database.connections.racer' => config("database.connections.{$default}")]);
    /** @var Connection $racer */
    $racer = DB::connection('racer');
    $exercisesBefore = (int) DB::table('exercises')->max('id');
    DB::setDefaultConnection('racer');
    try {
        $user = TodaySeed::user();
        TodaySeed::mesocycle($user);
        TrainSeed::phase($user, 'fatLoss', '2026-09-12', '2026-12-05');
    } finally {
        DB::setDefaultConnection($default);
    }
    $user = User::query()->findOrFail($user->id);

    $racer->beginTransaction();
    try {
        $racer->table('plans')->where('user_id', $user->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        expect(fn () => app(RebuildTargets::class)($user))->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        $racer->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $written = DayTarget::query()->where('user_id', $user->id)->where('date', '>', '2026-09-23')->exists();
        $racer->table('users')->where('id', $user->id)->delete();
        $racer->table('exercises')->where('id', '>', $exercisesBefore)->delete();
        DB::purge('racer');
    }

    expect($written)->toBeFalse();
});
