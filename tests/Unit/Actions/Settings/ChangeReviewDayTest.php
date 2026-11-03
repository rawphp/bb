<?php

use App\Actions\Settings\UpdateScheduleDefaults;
use App\Capabilities\Settings\Data\ScheduleDefaultsUpdateInput;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanReviewDayChange;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRebuild;

// PLAN-008 through SET-005: the review day changes from the next plan-week boundary. The plan started Monday
// 2026-09-07 and reviews on Mondays; today is Wednesday 2026-09-23, in week 2 (week starts: 09-07, 09-14, 09-21, 09-28,
// 10-05, 10-12). The rebuild is FakeRebuild, so these tests show when it runs, not what it writes.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

const REVIEW_PHASE_A = '11111111-1111-4111-8111-111111111111';
const REVIEW_PHASE_B = '22222222-2222-4222-8222-222222222222';

/**
 * A plan with two phases (A to 10-12, B open from there), a mesocycle in week 4, a draft with no start, a draft with a
 * start in week 4 and flagged plan weeks in weeks 2, 3 and 5.
 */
function reviewDayUser(string $start = '2026-09-07'): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create([
        'eating_window' => ['start' => '07:00', 'end' => '21:00'], 'meal_count' => 4, 'busy_periods' => [],
        'training_days' => [['weekday' => 4, 'start' => '17:30', 'minutes' => 60]],
        'weigh_in_days' => [['weekday' => 1, 'time' => '07:00']],
        'step_plan' => [],
    ]);
    Plan::factory()->for($user)->create(['start_date' => $start, 'review_weekday' => 1]);
    // The Monday grid: week 0 is the start, week 1 the first Monday after it.
    $gridStart = fn (int $week) => $week === 0 ? $start
        : (new DateTimeImmutable($start))->modify('next monday')->modify('+'.(7 * ($week - 1)).' days')->format('Y-m-d');
    Phase::factory()->for($user)->create([
        'id' => REVIEW_PHASE_A, 'type' => 'fatLoss', 'start_date' => $start, 'end_date' => $gridStart(5), 'target_rate_percent_per_week' => -0.5,
    ]);
    Phase::factory()->for($user)->create([
        'id' => REVIEW_PHASE_B, 'type' => 'maintenance', 'start_date' => $gridStart(5), 'end_date' => null, 'target_rate_percent_per_week' => 0,
    ]);
    $days = [['id' => 'push', 'label' => 'Push', 'weekday' => 4, 'slots' => [['id' => 'push-chest', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 12]]]];
    Mesocycle::factory()->for($user)->create([
        'phase_id' => REVIEW_PHASE_A, 'name' => 'Meso 1', 'weeks' => 4, 'start_date' => $gridStart(4), 'days' => $days, 'muscle_priorities' => null,
    ]);
    Mesocycle::factory()->for($user)->create([
        'phase_id' => REVIEW_PHASE_A, 'name' => 'Draft with a start', 'weeks' => 4, 'start_date' => $gridStart(6), 'is_draft' => true, 'days' => $days, 'muscle_priorities' => null,
    ]);
    Mesocycle::factory()->for($user)->create([
        'phase_id' => null, 'name' => 'Draft', 'weeks' => 4, 'start_date' => null, 'is_draft' => true, 'days' => $days, 'muscle_priorities' => null,
    ]);
    foreach ([2, 3, 5] as $week) {
        PlanWeek::factory()->for($user)->create(['start_date' => $gridStart($week), 'is_deload' => true]);
    }

    return $user;
}

function changeReviewDay(User $user, int $iso, array $changes = []): array
{
    return app(UpdateScheduleDefaults::class)($user, ScheduleDefaultsUpdateInput::validate(array_replace([
        'eating_window_start' => '07:00',
        'eating_window_end' => '21:00',
        'meal_count' => 4,
        'training_days' => [['weekday' => 4, 'start' => '17:30', 'minutes' => 60]],
        'busy_periods' => [],
        'step_plan' => [],
        'review_weekday' => $iso,
    ], $changes)));
}

/** @return list<string> the plan weeks' first days, Y-m-d, for weeks 0 to $count - 1 */
function reviewWeekStarts(User $user, int $count = 8): array
{
    $grid = PlanSnapshot::load($user)->grid;

    return array_map(fn (int $week) => $grid->start($week)->format('Y-m-d'), range(0, $count - 1));
}

/** @return list<array{string, int}> */
function reviewRows(User $user): array
{
    return PlanReviewDayChange::query()->whereIn('plan_id', Plan::query()->where('user_id', $user->id)->select('id'))
        ->orderBy('effective_on')->get()->map(fn (PlanReviewDayChange $row) => [$row->effective_on->format('Y-m-d'), $row->review_weekday])->all();
}

/** @return array<string, string|null> what the stored dates are, by name */
function reviewDates(User $user): array
{
    $phase = fn (string $id) => Phase::query()->findOrFail($id);
    $meso = fn (string $name) => Mesocycle::query()->where('user_id', $user->id)->where('name', $name)->firstOrFail()->start_date?->format('Y-m-d');

    return [
        'phase A start' => $phase(REVIEW_PHASE_A)->start_date->format('Y-m-d'),
        'phase A end' => $phase(REVIEW_PHASE_A)->end_date?->format('Y-m-d'),
        'phase B start' => $phase(REVIEW_PHASE_B)->start_date->format('Y-m-d'),
        'meso' => $meso('Meso 1'),
        'draft with a start' => $meso('Draft with a start'),
        'draft' => $meso('Draft'),
        'plan weeks' => PlanWeek::query()->where('user_id', $user->id)->orderBy('start_date')->get()->map(fn (PlanWeek $week) => $week->start_date->format('Y-m-d'))->implode(','),
    ];
}

it('PLAN-008 past weeks do not move', function () {
    FakeRebuild::bind();
    $user = reviewDayUser();

    changeReviewDay($user, 4);

    expect(array_slice(reviewWeekStarts($user), 0, 4))->toBe(['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'])
        ->and(reviewRows($user))->toBe([['2026-09-28', 4]])
        ->and(Plan::query()->where('user_id', $user->id)->value('review_weekday'))->toBe(1);
});

it('PLAN-008 exactly one week is shortened or lengthened to fit', function (int $iso, array $starts) {
    FakeRebuild::bind();
    $user = reviewDayUser();

    changeReviewDay($user, $iso);

    expect(array_slice(reviewWeekStarts($user), 3, 4))->toBe($starts);
})->with([
    // Thursday: week 3 would be 3 days, so it runs 10 days; every later week is 7.
    'Thursday' => [4, ['2026-09-28', '2026-10-08', '2026-10-15', '2026-10-22']],
    // Wednesday: week 3 runs 9 days.
    'Wednesday' => [3, ['2026-09-28', '2026-10-07', '2026-10-14', '2026-10-21']],
    // Saturday: week 3 is 5 days, shortened.
    'Saturday' => [6, ['2026-09-28', '2026-10-03', '2026-10-10', '2026-10-17']],
    // Sunday: week 3 is 6 days.
    'Sunday' => [7, ['2026-09-28', '2026-10-04', '2026-10-11', '2026-10-18']],
]);

it('PLAN-008 a change made on a boundary day takes effect from the next boundary', function () {
    FakeRebuild::bind();
    Carbon::setTestNow('2026-09-21 12:00:00');
    $user = reviewDayUser();

    changeReviewDay($user, 4);

    expect(reviewRows($user))->toBe([['2026-09-28', 4]]);
});

it('PLAN-008 two changes in a row keep both', function () {
    FakeRebuild::bind();
    $user = reviewDayUser();
    changeReviewDay($user, 4);
    $first = PlanReviewDayChange::query()->firstOrFail();

    // Week 4 starts on Thursday 2026-10-08; the next boundary is 10-15.
    Carbon::setTestNow('2026-10-08 12:00:00');
    changeReviewDay($user, 1);

    expect(reviewRows($user))->toBe([['2026-09-28', 4], ['2026-10-15', 1]])
        ->and(PlanReviewDayChange::query()->orderBy('effective_on')->first()->only(['id', 'updated_at']))->toEqual($first->only(['id', 'updated_at']));
});

it('PLAN-008 changing the day and changing it back before the boundary leaves no change behind', function () {
    FakeRebuild::bind();
    $user = reviewDayUser();
    $before = reviewDates($user);

    changeReviewDay($user, 4);
    expect(reviewDates($user))->not->toBe($before);
    changeReviewDay($user, 1);

    expect(reviewRows($user))->toBe([])
        ->and(reviewDates($user))->toBe($before)
        ->and(reviewWeekStarts($user, 6))->toBe(['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12']);
});

it('PLAN-008 SET-005 resending the same day is a no-op', function (?int $first) {
    FakeRebuild::bind();
    $user = reviewDayUser();
    if ($first !== null) {
        changeReviewDay($user, $first);
    }
    $rows = DB::table('plan_review_day_changes')->get();
    $dates = reviewDates($user);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        // The profile's own save is SET-004's; the plan, its changes and what sits on its grid are this test's.
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! str_contains($query->sql, '`profiles`')) {
            $writes[] = $query->sql;
        }
    });

    changeReviewDay($user, $first ?? 1);

    expect($writes)->toBe([])
        ->and(DB::table('plan_review_day_changes')->get()->all())->toEqual($rows->all())
        ->and(reviewDates($user))->toBe($dates);
})->with(['the starting day' => [null], 'a pending day' => [4]]);

it('PLAN-008 ONB-016 before the plan starts a change sets the starting review day', function () {
    $rebuild = FakeRebuild::bind();
    // The plan starts Wednesday 2026-09-30, so week 0 is short: Monday's weeks start 10-05, Thursday's 10-01.
    $user = reviewDayUser('2026-09-30');

    changeReviewDay($user, 4);

    expect(Plan::query()->where('user_id', $user->id)->value('review_weekday'))->toBe(4)
        ->and(reviewRows($user))->toBe([])
        ->and(array_slice(reviewWeekStarts($user), 0, 3))->toBe(['2026-09-30', '2026-10-01', '2026-10-08'])
        ->and(Phase::query()->findOrFail(REVIEW_PHASE_A)->end_date->format('Y-m-d'))->toBe('2026-10-29')
        ->and($rebuild->calls)->toBe([['user' => $user->id, 'beforeStart' => true]]);
});

it('PLAN-008 a change after the plan starts does not rebuild the targets', function () {
    $rebuild = FakeRebuild::bind();
    $user = reviewDayUser();

    changeReviewDay($user, 4);

    expect($rebuild->calls)->toBe([]);
});

it('PLAN-002 phases, mesocycles and plan weeks stored after the boundary keep their week index on the new grid', function () {
    FakeRebuild::bind();
    $user = reviewDayUser();

    changeReviewDay($user, 4);

    // Thursday: weeks 4, 5 and 6 start 10-08, 10-15 and 10-22. Week 3 (09-28) is the transition week and keeps its row.
    expect(reviewDates($user))->toBe([
        'phase A start' => '2026-09-07',
        'phase A end' => '2026-10-15',
        'phase B start' => '2026-10-15',
        'meso' => '2026-10-08',
        'draft with a start' => '2026-10-22',
        'draft' => null,
        'plan weeks' => '2026-09-21,2026-09-28,2026-10-15',
    ]);
});
