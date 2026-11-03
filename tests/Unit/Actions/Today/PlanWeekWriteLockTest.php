<?php

use App\Actions\Today\SetAwayWeeks;
use App\Actions\Today\SetRoughWeek;
use App\Capabilities\Today\Data\AwayWeekSetInput;
use App\Capabilities\Today\Data\RoughWeekSetInput;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// SCH-060, SCH-063, PLAN-025: away_week_set and rough_week_set wait for whatever else holds the user's plan row, so two
// saves cannot both read the same phase end or both add a week's row. The rows are committed through a second
// connection (as ResolveMissedWeekLockTest does), so this file runs without RefreshDatabase and removes what it made.
// The plan's weeks start on Saturday from 2026-09-12; today is Wednesday 2026-09-23.

afterEach(fn () => Carbon::setTestNow());

/**
 * Runs `$write` while a second connection holds the user's plan row, and removes the committed rows after.
 *
 * @param  Closure(User): mixed  $write
 */
function whilePlanLocked(Closure $write): User
{
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

        expect(fn () => $write($user))->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        $racer->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $racer->table('users')->where('id', $user->id)->delete();
        $racer->table('exercises')->where('id', '>', $exercisesBefore)->delete();
        DB::purge('racer');
    }

    return $user;
}

it('SCH-060 PLAN-025 away_week_set takes the plan row lock, so it waits for a write that holds it', function () {
    $user = whilePlanLocked(fn (User $user) => app(SetAwayWeeks::class)($user, new AwayWeekSetInput(['2026-09-26'], true, 'pause', 'maintenance', null)));

    expect(PlanWeek::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('SCH-063 rough_week_set takes the plan row lock, so it waits for a write that holds it', function () {
    $user = whilePlanLocked(fn (User $user) => app(SetRoughWeek::class)($user, new RoughWeekSetInput('2026-09-19', true)));

    expect(PlanWeek::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
