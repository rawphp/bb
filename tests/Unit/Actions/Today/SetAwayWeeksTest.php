<?php

use App\Actions\Today\SetAwayWeeks;
use App\Capabilities\Reads\Today\AwayWeekPayload;
use App\Capabilities\Today\Data\AwayWeekSetInput;
use App\Models\Phase;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\AwayOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// What away_week_set saves (SCH-060, PLAN-025). The rules are AwayWeeksTest's. The plan's weeks start on Saturday from
// 2026-09-12 and today is Wednesday 2026-09-23 (week 1), so the weeks from 2026-09-26 are offered. Meso A fills the
// weeks from 2026-09-12 to 2026-10-09; the Fat loss phase runs to 2026-12-05 and the Muscle gain phase after it to 2027-03-06.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Phase, Phase} the user with the Fat loss phase and a later one */
function awayWorld(?string $end = '2026-12-05', string $type = 'fatLoss'): array
{
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);
    $phase = TrainSeed::phase($user, $type, '2026-09-12', $end);
    $later = TrainSeed::phase($user, 'muscleGain', '2026-12-05', '2027-03-06');

    return [$user, $phase, $later];
}

/** @param  list<string>  $weeks */
function setAway(User $user, array $weeks, bool $away = true, string $training = 'pause', string $calories = 'maintenance', ?bool $keep = null): array
{
    return app(SetAwayWeeks::class)($user, new AwayWeekSetInput($weeks, $away, $training, $calories, $keep));
}

function awayRowOf(User $user, string $start): ?PlanWeek
{
    return PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', $start)->first();
}

function endOf(Phase $phase): string
{
    return PlanSnapshot::day($phase->fresh()->end_date)->format('Y-m-d');
}

function startOf(Phase $phase): string
{
    return PlanSnapshot::day($phase->fresh()->start_date)->format('Y-m-d');
}

function sheetFor(User $user, string $week): array
{
    return AwayWeekPayload::build(AwayOffer::fromSnapshot(PlanSnapshot::load($user)), $week)->toArray();
}

// Saving the weeks.

it('SCH-060 creates the row of a week with its choices, and keeps what the row already had', function () {
    [$user] = awayWorld();

    setAway($user, ['2026-10-03'], training: 'deload', calories: 'rest');

    $row = awayRowOf($user, '2026-10-03');
    expect([$row->is_away, $row->away_training, $row->away_calories, $row->is_diet_break])->toBe([true, 'deload', 'rest_days', false])
        // TodaySeed's row of that week was flagged a deload, and stays so.
        ->and($row->is_deload)->toBeTrue();
});

it('SCH-060 marks every week sent, once each, creating rows for the ones without', function () {
    [$user] = awayWorld();

    setAway($user, ['2026-09-26', '2026-10-17', '2026-09-26'], calories: 'rest');

    expect(awayRowOf($user, '2026-09-26')->is_away)->toBeTrue()
        ->and(awayRowOf($user, '2026-10-17')->is_away)->toBeTrue()
        ->and(PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', '2026-09-26')->count())->toBe(1)
        ->and(awayRowOf($user, '2026-10-17')->is_deload)->toBeFalse();
});

it('SCH-060 updates the row a week already has instead of adding another', function () {
    [$user] = awayWorld();
    $before = PlanWeek::query()->where('user_id', $user->id)->count();

    setAway($user, ['2026-10-03'], calories: 'rest');
    setAway($user, ['2026-10-03'], training: 'deload', calories: 'rest');

    expect(PlanWeek::query()->where('user_id', $user->id)->count())->toBe($before)
        ->and(awayRowOf($user, '2026-10-03')->away_training)->toBe('deload');
});

it('SCH-060 maintenance in Fat loss makes the week a diet break', function () {
    [$user] = awayWorld();

    setAway($user, ['2026-09-26'], calories: 'maintenance');

    $row = awayRowOf($user, '2026-09-26');
    expect([$row->away_calories, $row->is_diet_break])->toBe(['maintenance', true]);
});

it('SCH-060 rest days in Fat loss, and maintenance in Muscle gain, are not a diet break', function () {
    [$user] = awayWorld();
    [$gain] = awayWorld(type: 'muscleGain');

    setAway($user, ['2026-09-26'], calories: 'rest');
    setAway($gain, ['2026-09-26'], calories: 'maintenance');

    expect(awayRowOf($user, '2026-09-26')->is_diet_break)->toBeFalse()
        ->and(awayRowOf($gain, '2026-09-26')->is_diet_break)->toBeFalse();
});

it('SCH-060 refuses a week the sheet does not offer, and saves nothing', function (array $weeks) {
    [$user, $phase] = awayWorld();
    $before = PlanWeek::query()->count();

    expect(fn () => setAway($user, ['2026-09-26', ...$weeks]))->toThrow(DomainException::class)
        ->and(PlanWeek::query()->count())->toBe($before)
        ->and(endOf($phase))->toBe('2026-12-05');
})->with([
    'the week in progress' => [['2026-09-19']],
    'a past week' => [['2026-09-12']],
    'past the phase' => [['2026-12-05']],
    'a day that does not start a week' => [['2026-09-27']],
]);

it('SCH-060 refuses no weeks at all', function () {
    [$user] = awayWorld();

    expect(fn () => setAway($user, []))->toThrow(DomainException::class);
});

it('SCH-060 refuses the deload when a marked week has fewer than three training weeks before it, and saves nothing', function () {
    [$user] = awayWorld();
    $before = PlanWeek::query()->count();

    expect(fn () => setAway($user, ['2026-09-26'], training: 'deload'))->toThrow(DomainException::class)
        ->and(fn () => setAway($user, ['2026-09-26', '2026-10-03'], training: 'deload'))->toThrow(DomainException::class)
        ->and(PlanWeek::query()->count())->toBe($before);

    setAway($user, ['2026-10-03'], training: 'deload');
    expect(awayRowOf($user, '2026-10-03')->away_training)->toBe('deload');
});

it('SCH-060 "Not away" clears the away fields and the diet break it made', function () {
    [$user] = awayWorld();
    setAway($user, ['2026-09-26'], training: 'pause', calories: 'maintenance', keep: true);

    setAway($user, ['2026-09-26'], away: false);

    $row = awayRowOf($user, '2026-09-26');
    expect([$row->is_away, $row->away_training, $row->away_calories, $row->is_diet_break, $row->keep_phase_end])->toBe([false, null, null, false, false]);
});

it('PLAN-025 a diet break set some other way stays one when its week is saved or cleared, and moves nothing', function () {
    [$user, $phase] = awayWorld();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-26', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'rest_days', 'is_diet_break' => true]);

    setAway($user, ['2026-09-26'], calories: 'rest');
    expect(awayRowOf($user, '2026-09-26')->is_diet_break)->toBeTrue()
        ->and(endOf($phase))->toBe('2026-12-05');

    setAway($user, ['2026-09-26'], away: false);
    expect(awayRowOf($user, '2026-09-26')->is_diet_break)->toBeTrue()
        ->and(endOf($phase))->toBe('2026-12-05');
});

it('PLAN-025 a week that was a diet break before it was away adds no week to the phase', function () {
    [$user, $phase] = awayWorld();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-26', 'is_diet_break' => true]);

    setAway($user, ['2026-09-26'], calories: 'maintenance');

    expect(endOf($phase))->toBe('2026-12-05');
});

// The end date.

it('PLAN-025 the phase end moves a week later for each week that becomes a diet break, and the later phase moves with it', function () {
    [$user, $phase, $later] = awayWorld();

    setAway($user, ['2026-09-26', '2026-10-10'], calories: 'maintenance');

    expect(endOf($phase))->toBe('2026-12-19')
        ->and(startOf($phase))->toBe('2026-09-12')
        ->and(startOf($later))->toBe('2026-12-19')
        ->and(endOf($later))->toBe('2027-03-20');
});

it('PLAN-025 a week that is not a diet break leaves the end date', function () {
    [$user, $phase, $later] = awayWorld();

    setAway($user, ['2026-09-26'], calories: 'rest');

    expect(endOf($phase))->toBe('2026-12-05')
        ->and(startOf($later))->toBe('2026-12-05');
});

it('PLAN-025 a phase with no end date moves nothing', function () {
    [$user, $phase] = awayWorld(end: null);

    setAway($user, ['2026-09-26'], calories: 'maintenance');

    expect($phase->fresh()->end_date)->toBeNull();
});

it('PLAN-025 clearing an away diet break brings the end date a week sooner', function () {
    [$user, $phase, $later] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance');
    expect(endOf($phase))->toBe('2026-12-12');

    setAway($user, ['2026-09-26'], away: false);

    expect(endOf($phase))->toBe('2026-12-05')
        ->and(startOf($later))->toBe('2026-12-05');
});

it('PLAN-025 editing an away diet break to rest days brings the end date a week sooner', function () {
    [$user, $phase] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance');

    setAway($user, ['2026-09-26'], calories: 'rest');

    expect(endOf($phase))->toBe('2026-12-05')
        ->and(awayRowOf($user, '2026-09-26')->is_diet_break)->toBeFalse();
});

it('PLAN-025 editing an away diet break without changing it moves nothing', function () {
    [$user, $phase] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance');

    setAway($user, ['2026-09-26'], training: 'pause', calories: 'maintenance');

    expect(endOf($phase))->toBe('2026-12-12');
});

// The saved "keep the phase end date" (PLAN-025, the f1 fix of PR #224).

it('PLAN-025 (a) a week marked away with maintenance and keep_phase_end leaves the end date, and its sheet comes back keeping it', function () {
    [$user, $phase] = awayWorld();

    setAway($user, ['2026-09-26'], calories: 'maintenance', keep: true);

    $sheet = sheetFor($user, '2026-09-26');
    expect(endOf($phase))->toBe('2026-12-05')
        ->and(awayRowOf($user, '2026-09-26')->keep_phase_end)->toBeTrue()
        ->and($sheet['keep_phase_end'])->toBeTrue()
        ->and($sheet['is_editing'])->toBeTrue();
});

it('PLAN-025 (b) editing that week to rest days without keep_phase_end leaves the end date and the saved value', function () {
    [$user, $phase] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance', keep: true);

    setAway($user, ['2026-09-26'], calories: 'rest');

    expect(endOf($phase))->toBe('2026-12-05')
        ->and(awayRowOf($user, '2026-09-26')->keep_phase_end)->toBeTrue()
        ->and(sheetFor($user, '2026-09-26')['keep_phase_end'])->toBeTrue();
});

it('PLAN-025 (c) the same edit on a week saved with keep_phase_end false moves the end date 7 days earlier', function () {
    [$user, $phase] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance', keep: false);
    expect(endOf($phase))->toBe('2026-12-12')
        ->and(awayRowOf($user, '2026-09-26')->keep_phase_end)->toBeFalse();

    setAway($user, ['2026-09-26'], calories: 'rest');

    expect(endOf($phase))->toBe('2026-12-05');
});

it('PLAN-025 (d) "Not away" with no keep_phase_end on a week saved with true moves no dates, and clears the saved value', function () {
    [$user, $phase, $later] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance', keep: true);

    setAway($user, ['2026-09-26'], away: false);

    expect(endOf($phase))->toBe('2026-12-05')
        ->and(startOf($later))->toBe('2026-12-05')
        ->and(awayRowOf($user, '2026-09-26')->keep_phase_end)->toBeFalse()
        ->and(sheetFor($user, '2026-09-26')['is_editing'])->toBeFalse();
});

it('PLAN-025 a sent keep_phase_end replaces the saved one for that save', function () {
    [$user, $phase] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance', keep: true);

    setAway($user, ['2026-09-26'], calories: 'rest', keep: false);

    expect(endOf($phase))->toBe('2026-11-28')
        ->and(awayRowOf($user, '2026-09-26')->keep_phase_end)->toBeFalse();
});

it('PLAN-025 weeks saved with different keep values move the end date only for the ones that do not keep it', function () {
    [$user, $phase] = awayWorld();
    setAway($user, ['2026-09-26'], calories: 'maintenance', keep: true);
    setAway($user, ['2026-10-03'], calories: 'maintenance', keep: false);
    expect(endOf($phase))->toBe('2026-12-12');

    setAway($user, ['2026-09-26', '2026-10-03'], calories: 'rest');

    // The first keeps its end date, the second is one diet break less.
    expect(endOf($phase))->toBe('2026-12-05');
});

// What it returns.

it('SCH-060 returns the days to drop, the weeks the sheet offers and a toast', function () {
    [$user] = awayWorld();

    $result = setAway($user, ['2026-09-26'], calories: 'rest');

    expect($result['dates'])->toBe(['2026-09-26', '2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'])
        ->and($result['offered'])->toContain('2026-09-26', '2026-11-28')
        ->and($result['offered'])->not->toContain('2026-09-19', '2026-12-05')
        ->and($result['toast'])->toBe('Week marked away');
});

it('SCH-060 the toast says what was done', function () {
    [$user] = awayWorld();

    $many = setAway($user, ['2026-09-26', '2026-10-17'], calories: 'rest')['toast'];
    $edited = setAway($user, ['2026-09-26'], training: 'pause', calories: 'rest')['toast'];
    $cleared = setAway($user, ['2026-09-26'], away: false)['toast'];
    $clearedMany = setAway($user, ['2026-10-17'], away: false)['toast'];

    expect([$many, $edited, $cleared, $clearedMany])->toBe(['Weeks marked away', 'Away week updated', 'Week no longer away', 'Week no longer away']);
});

it('SCH-060 the offered weeks come back from the phase as it ends after the move', function () {
    [$user] = awayWorld();

    $result = setAway($user, ['2026-09-26'], calories: 'maintenance');

    expect($result['offered'])->toContain('2026-12-05');
});

it('SCH-060 refuses when the user has no plan', function () {
    expect(fn () => setAway(User::factory()->create(), ['2026-09-26']))->toThrow(DomainException::class);
});
