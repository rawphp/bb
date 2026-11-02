<?php

use App\Actions\Today\ResolveMissedWeek;
use App\Capabilities\Today\Data\MissedWeekResolveInput;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// What missed_week_resolve saves (SCH-058). The prompt is MissedWeeksPromptTest's. Plan weeks run Monday from 31 August
// (week 0). Meso 2 has 5 weeks from Monday 7 September (plan week 1) and trains Mon and Thu. Today is Tuesday 29
// September (plan week 4) and nothing was trained, so the stopped week is its first and the start moves 3 plan weeks
// to Monday 28 September.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle} */
function missedWeekWorld(string $today = '2026-09-29'): array
{
    Carbon::setTestNow("{$today} 08:00:00");
    $user = TrainSeed::user();

    return [$user, TrainSeed::mesocycle($user)];
}

function resolveWeek(User $user, Mesocycle $mesocycle, string $choice = 'restart', bool $dietBreak = false): array
{
    return app(ResolveMissedWeek::class)($user, new MissedWeekResolveInput($mesocycle->id, $choice, $dietBreak));
}

/** @param  array<string, mixed>  $attributes */
function weekRow(User $user, string $start, array $attributes = []): PlanWeek
{
    return PlanWeek::factory()->for($user)->create(array_replace(['start_date' => $start, 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false], $attributes));
}

function flags(string $start): array
{
    $row = PlanWeek::query()->whereDate('start_date', $start)->first();

    return $row === null ? [] : [$row->is_deload, $row->is_diet_break, $row->diet_break_off];
}

it('SCH-058 restart moves the start by whole plan weeks so the stopped week is this one', function () {
    [$user, $mesocycle] = missedWeekWorld();

    $resolved = resolveWeek($user, $mesocycle);

    expect($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-28')
        ->and($mesocycle->fresh()->ended_on)->toBeNull()
        ->and($resolved['toast'])->toBe('Week restarted')
        ->and($resolved['dates'])->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
});

it('SCH-058 restart refuses when nothing is due', function () {
    [$user, $mesocycle] = missedWeekWorld('2026-09-09');

    expect(fn () => resolveWeek($user, $mesocycle))->toThrow(DomainException::class);
    expect($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-07');
});

it('SCH-058 restart refuses when the weeks were skipped right up to this one', function () {
    [$user, $mesocycle] = missedWeekWorld();
    TrainSeed::trained($mesocycle, 1, 1, '2026-09-07');
    TrainSeed::trained($mesocycle, 1, 2, '2026-09-10');
    foreach ([2, 3] as $week) {
        foreach ([1, 2] as $day) {
            WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => $day, 'is_skipped' => true]);
        }
    }

    expect(fn () => resolveWeek($user, $mesocycle))->toThrow(DomainException::class);
    expect($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-07');
});

it('SCH-058 PLAN-022 restart moves a stored deload with the mesocycle and its diet break with it', function () {
    [$user, $mesocycle] = missedWeekWorld();
    weekRow($user, '2026-09-21', ['is_deload' => true, 'is_diet_break' => true]);

    resolveWeek($user, $mesocycle);

    expect(flags('2026-09-21'))->toBe([false, false, false])
        ->and(flags('2026-10-12'))->toBe([true, true, false])
        ->and(PlanWeek::query()->count())->toBe(2);
});

it('SCH-058 PLAN-022 a deload moving onto a week that already is one leaves one deload there', function () {
    [$user, $mesocycle] = missedWeekWorld();
    weekRow($user, '2026-09-21', ['is_deload' => true]);
    // 12 October is past the old mesocycle, so that flag stays.
    weekRow($user, '2026-10-12', ['is_deload' => true]);

    resolveWeek($user, $mesocycle);

    expect(flags('2026-09-21'))->toBe([false, false, false])
        ->and(flags('2026-10-12'))->toBe([true, false, false]);
});

it('SCH-058 SCH-060 an away week\'s own deload stays on its calendar week', function () {
    [$user, $mesocycle] = missedWeekWorld();
    weekRow($user, '2026-09-21', ['is_deload' => true, 'is_away' => true, 'away_training' => 'keep']);

    resolveWeek($user, $mesocycle);

    expect(flags('2026-09-21'))->toBe([true, false, false])
        ->and(PlanWeek::query()->count())->toBe(1);
});

it('SCH-058 PLAN-023 a diet break turned off keeps that when it moves', function () {
    [$user, $mesocycle] = missedWeekWorld();
    weekRow($user, '2026-09-21', ['diet_break_off' => true]);

    resolveWeek($user, $mesocycle);

    expect(flags('2026-09-21'))->toBe([false, false, false])
        ->and(flags('2026-10-12'))->toBe([false, false, true]);
});

it('SCH-058 restart changes no day target', function () {
    [$user, $mesocycle] = missedWeekWorld();
    foreach (['2026-09-28', '2026-09-29', '2026-10-05'] as $date) {
        SheetSeed::day($user, $date);
    }
    weekRow($user, '2026-09-21', ['is_deload' => true]);
    $before = DayTarget::query()->orderBy('id')->get()->toArray();

    resolveWeek($user, $mesocycle);

    expect(DayTarget::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('SCH-058 MESO-043 end ends the mesocycle today', function () {
    [$user, $mesocycle] = missedWeekWorld();

    $resolved = resolveWeek($user, $mesocycle, 'end');

    expect($mesocycle->fresh()->ended_on->format('Y-m-d'))->toBe('2026-09-29')
        ->and($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-07')
        ->and($resolved['toast'])->toBe('Mesocycle ended');
});

it('SCH-058 MESO-044 end with a diet break makes the next plan week one', function () {
    [$user, $mesocycle] = missedWeekWorld();
    $phase = TrainSeed::phase($user, 'fatLoss', '2026-08-31');
    $mesocycle->update(['phase_id' => $phase->id]);

    $resolved = resolveWeek($user, $mesocycle, 'end', dietBreak: true);

    expect($resolved['toast'])->toBe('Mesocycle ended. Next week is a diet break.')
        ->and(flags('2026-10-05'))->toBe([false, true, false]);
});

it('SCH-058 MESO-044 end refuses a diet break outside fat loss', function () {
    [$user, $mesocycle] = missedWeekWorld();

    expect(fn () => resolveWeek($user, $mesocycle, 'end', dietBreak: true))->toThrow(DomainException::class);
    expect($mesocycle->fresh()->ended_on)->toBeNull();
});

// What a restart or an end changes (SCH-058, SCH-023): every later session, deload and menu, so every day the client
// can browse to from the plan week in progress.

it('SCH-058 SCH-023 a restart names the days of the old placement, back to the plan week of the old start, through the strip week of the last target', function () {
    [$user, $mesocycle] = missedWeekWorld();
    SheetSeed::day($user, '2026-11-02');

    $resolved = resolveWeek($user, $mesocycle);

    // Meso 2 started Monday 7 Sep: its missed sessions leave those days. 2 Nov is a Monday, so its strip runs to 8 Nov.
    expect($resolved['dates'][0])->toBe('2026-09-07')
        ->and($resolved['dates'])->toContain('2026-09-14', '2026-09-27', '2026-10-12', '2026-10-13', '2026-11-08')
        ->and(end($resolved['dates']))->toBe('2026-11-08')
        ->and($resolved['dates'])->toHaveCount(63);
});

it('SCH-058 SCH-023 an end names every day from the plan week in progress through the strip week of the last target', function () {
    [$user, $mesocycle] = missedWeekWorld();
    SheetSeed::day($user, '2026-11-02');

    $resolved = resolveWeek($user, $mesocycle, 'end');

    expect($resolved['dates'][0])->toBe('2026-09-28')
        ->and(end($resolved['dates']))->toBe('2026-11-08')
        ->and($resolved['dates'])->toHaveCount(42);
});

it('SCH-058 SCH-023 an end names the whole plan week in progress even when the last target is earlier', function () {
    [$user, $mesocycle] = missedWeekWorld();
    SheetSeed::day($user, '2026-09-29');

    expect(resolveWeek($user, $mesocycle, 'end')['dates'])->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
});

it('SCH-058 end accepts only a mesocycle the prompt is due for', function () {
    [$user, $mesocycle] = missedWeekWorld('2026-09-09');

    expect(fn () => resolveWeek($user, $mesocycle, 'end'))->toThrow(DomainException::class, ResolveMissedWeek::NOT_DUE);
    expect($mesocycle->fresh()->ended_on)->toBeNull();
});

it('SCH-058 end refuses a mesocycle that is not the one the prompt is about', function () {
    [$user, $mesocycle] = missedWeekWorld();
    $other = TrainSeed::mesocycle($user, ['name' => 'Earlier', 'start_date' => '2026-08-31', 'weeks' => 1]);

    expect(fn () => resolveWeek($user, $other, 'end'))->toThrow(DomainException::class, ResolveMissedWeek::NOT_DUE);
    expect($other->fresh()->ended_on)->toBeNull()->and($mesocycle->fresh()->ended_on)->toBeNull();
});

it('SCH-058 SCH-060 a restart counts a paused away week inside the mesocycle, so the start moves one plan week less', function () {
    // Meso 2 runs plan weeks 1 to 5 with week 2 (from 14 Sep) paused: trained in week 1, then nothing. Its week 2 is
    // plan week 3, so the stopped week is mesocycle week 2. Moving the start one week puts that week on plan week 4;
    // a mutant ignoring the pause would need two.
    [$user, $mesocycle] = missedWeekWorld();
    TrainSeed::trained($mesocycle, 1, 1, '2026-09-07');
    TrainSeed::trained($mesocycle, 1, 2, '2026-09-10');
    weekRow($user, '2026-09-14', ['is_away' => true, 'away_training' => 'pause']);

    resolveWeek($user, $mesocycle);

    expect($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-14');
});

it('SCH-058 two restarts run one after the other: the second finds nothing due', function () {
    [$user, $mesocycle] = missedWeekWorld();

    resolveWeek($user, $mesocycle);

    expect(fn () => resolveWeek($user, $mesocycle))->toThrow(DomainException::class);
    expect($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-28');
});
