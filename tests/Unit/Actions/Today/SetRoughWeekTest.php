<?php

use App\Actions\Today\SetRoughWeek;
use App\Capabilities\Today\Data\RoughWeekSetInput;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TodaySeed;

// What rough_week_set saves (SCH-063: the flag is stored and checked, nothing else). The plan's weeks start on Saturday
// from 2026-09-12; today is Wednesday 2026-09-23, in the week from 2026-09-19. A 4-week mesocycle from the plan start
// makes the week from 2026-10-03 its deload.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function roughWorld(): User
{
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);

    return $user;
}

function setRough(User $user, bool $rough, string $week = '2026-09-19'): array
{
    return app(SetRoughWeek::class)($user, new RoughWeekSetInput($week, $rough));
}

function roughRow(User $user, string $start = '2026-09-19'): ?PlanWeek
{
    return PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', $start)->first();
}

it('SCH-063 marks this plan week rough, creating its row', function () {
    $user = roughWorld();
    expect(roughRow($user))->toBeNull();

    setRough($user, true);

    $row = roughRow($user);
    expect([$row->is_rough, $row->is_deload, $row->is_diet_break, $row->is_away])->toBe([true, false, false, false]);
});

it('SCH-063 unmarks the week again, keeping the rest of its row', function () {
    $user = roughWorld();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-19', 'is_rough' => true, 'is_diet_break' => true]);

    setRough($user, false);

    $row = roughRow($user);
    expect([$row->is_rough, $row->is_diet_break])->toBe([false, true])
        ->and(PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', '2026-09-19')->count())->toBe(1);
});

it('SCH-063 unmarking a week that was never rough saves nothing new but still answers', function () {
    $user = roughWorld();

    $result = setRough($user, false);

    expect(roughRow($user)?->is_rough ?? false)->toBeFalse()
        ->and($result['toast'])->toBe('Rough week unmarked');
});

it('SCH-063 accepts only the start of the plan week of today', function (string $week) {
    $user = roughWorld();
    $before = PlanWeek::query()->count();

    expect(fn () => setRough($user, true, $week))->toThrow(DomainException::class)
        ->and(PlanWeek::query()->count())->toBe($before);
})->with([
    'next week' => '2026-09-26',
    'last week' => '2026-09-12',
    'a day inside this week' => '2026-09-23',
    'a far week' => '2027-01-02',
]);

it('SCH-063 refuses marking a deload week and saves nothing', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = roughWorld();
    $before = PlanWeek::query()->where('is_rough', true)->count();

    expect(fn () => setRough($user, true, '2026-10-03'))->toThrow(DomainException::class)
        ->and(PlanWeek::query()->where('is_rough', true)->count())->toBe($before);
});

it('SCH-063 refuses marking a week flagged a deload', function () {
    $user = roughWorld();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-19', 'is_deload' => true]);

    expect(fn () => setRough($user, true))->toThrow(DomainException::class)
        ->and(roughRow($user)->is_rough)->toBeFalse();
});

it('SCH-063 refuses marking an away week and saves nothing', function () {
    $user = roughWorld();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-19', 'is_away' => true, 'away_training' => 'deload']);

    expect(fn () => setRough($user, true))->toThrow(DomainException::class)
        ->and(roughRow($user)->is_rough)->toBeFalse();
});

it('SCH-063 allows unmarking while the week is in progress, even once it is away', function () {
    $user = roughWorld();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-19', 'is_rough' => true, 'is_away' => true, 'away_training' => 'pause']);

    setRough($user, false);

    expect(roughRow($user)->is_rough)->toBeFalse();
});

it('SCH-063 returns the days of the week and a toast', function () {
    $user = roughWorld();

    $result = setRough($user, true);

    expect($result['dates'])->toBe(['2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'])
        ->and($result['toast'])->toBe('Marked as a rough week');
});

it('SCH-063 refuses when the user has no plan', function () {
    expect(fn () => setRough(User::factory()->create(), true))->toThrow(DomainException::class);
});

it('SCH-063 refuses before the plan starts', function () {
    Carbon::setTestNow('2026-09-01 10:00:00');
    $user = roughWorld();

    expect(fn () => setRough($user, true, '2026-09-12'))->toThrow(DomainException::class);
});

// A plan that starts on Wednesday 16 Sep with Monday reviews: its first plan week is 16 to 20 Sep, and the week strip
// shows that week from Monday 14 Sep (SCH-020).

function midWeekWorld(): User
{
    Carbon::setTestNow('2026-09-17 10:00:00');
    $user = TodaySeed::user();
    Plan::query()->where('user_id', $user->id)->update(['start_date' => '2026-09-16', 'review_weekday' => 1]);

    return $user;
}

it('SCH-063 a plan starting mid-week accepts the week start the strip shows and stores the row at the plan week start', function () {
    $user = midWeekWorld();

    $result = setRough($user, true, '2026-09-14');

    expect(roughRow($user, '2026-09-16')->is_rough)->toBeTrue()
        ->and(roughRow($user, '2026-09-14'))->toBeNull()
        ->and($result['dates'])->toBe(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20']);
});

it('SCH-063 a plan starting mid-week still accepts the plan week start, and no other day', function () {
    $user = midWeekWorld();

    setRough($user, true, '2026-09-16');
    expect(roughRow($user, '2026-09-16')->is_rough)->toBeTrue();

    expect(fn () => setRough($user, true, '2026-09-15'))->toThrow(DomainException::class)
        ->and(fn () => setRough($user, true, '2026-09-21'))->toThrow(DomainException::class);
});
