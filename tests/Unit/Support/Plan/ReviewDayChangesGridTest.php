<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Reads\Today\AwayWeekPayload;
use App\Capabilities\Support\ReadKey;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Review\ReviewPlanRebuild;
use App\Support\Today\AdjustWeek;
use App\Support\Today\AwayOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

// PLAN-008: every grid built from the database applies the plan's stored review-day changes. The plan started Monday
// 2026-09-07; from 2026-09-28 its weeks start on Thursday, so that week runs 10 days and week 4 starts 2026-10-08.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function changedPlanUser(): User
{
    $user = User::factory()->create();
    $plan = Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    $plan->reviewDayChanges()->create(['effective_on' => '2026-09-28', 'review_weekday' => 4]);
    Phase::factory()->for($user)->create(['start_date' => '2026-09-07', 'end_date' => null]);

    return $user;
}

it('PLAN-008 the plan snapshot\'s grid and domain plan apply the stored changes', function () {
    $snapshot = PlanSnapshot::load(changedPlanUser());

    expect($snapshot->grid->start(4)->format('Y-m-d'))->toBe('2026-10-08')
        ->and($snapshot->domain->reviewDayChanges)->toHaveCount(1)
        ->and($snapshot->domain->currentReviewWeekday())->toBe(5);
});

it('PLAN-008 the review plan applies the stored changes', function () {
    $plan = Plan::query()->where('user_id', changedPlanUser()->id)->firstOrFail();

    $domain = ReviewPlanRebuild::plan($plan, collect());

    expect($domain->reviewDayChanges)->toHaveCount(1)
        ->and($domain->currentReviewWeekday())->toBe(5);
});

it('PLAN-008 the adjust sheet\'s plan week runs from the new review day', function () {
    $adjust = AdjustWeek::load(changedPlanUser(), '2026-10-10');

    expect($adjust->dates[0])->toBe('2026-10-08')
        ->and($adjust->dates[6])->toBe('2026-10-14');
});

/** @return list<string> the dated week_show keys a meal on $date drops */
function changedPlanMealWeek(string $date): array
{
    $user = changedPlanUser();
    $meal = (new DayTargetMeal)->forceFill(['id' => 'meal-1', 'user_id' => $user->id, 'day_target_id' => 'dt', 'position' => 0])
        ->setRelation('dayTarget', (new DayTarget)->forceFill(['id' => 'dt', 'user_id' => $user->id, 'date' => $date]))
        ->setRelation('user', $user);
    $dated = [];
    foreach (range(0, 30) as $offset) {
        $day = Carbon::parse('2026-09-20')->addDays($offset)->format('Y-m-d');
        $dated[$day] = ReadKey::for('week_show', ['date' => $day]);
    }
    $keys = MealReads::keys($meal);

    return array_keys(array_filter($dated, fn (string $key) => in_array($key, $keys, true)));
}

it('CAP-010 PLAN-008 a meal in the transition week drops the strip of its 10-day plan week', function () {
    expect(changedPlanMealWeek('2026-10-06'))->toBe([
        '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07',
    ]);
});

it('CAP-010 PLAN-008 a meal after the transition week drops the strip of the week starting on the new review day', function () {
    expect(changedPlanMealWeek('2026-10-10'))->toBe([
        '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14',
    ]);
});

/** The Away sheet for a week start, on the changed plan with plan weeks stored at $stored; the plan is in its week 2. */
function changedPlanAwaySheet(array $stored, string $week): array
{
    Carbon::setTestNow('2026-09-20 12:00:00');
    $user = changedPlanUser();
    foreach ($stored as $start) {
        PlanWeek::factory()->for($user)->create(['start_date' => $start, 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance']);
    }

    return AwayWeekPayload::build(AwayOffer::fromSnapshot(PlanSnapshot::load($user)), $week)->toArray();
}

it('SCH-060 PLAN-008 an away week stored on the new grid\'s week 4 is found there, and the 10-day week before it is week 3', function () {
    $fourth = changedPlanAwaySheet(['2026-10-08'], '2026-10-08');
    $third = changedPlanAwaySheet(['2026-09-28'], '2026-09-28');

    expect($fourth['is_editing'])->toBeTrue()
        ->and($fourth['weeks'][0]['label'])->toBe('Week 5 · Oct 8')
        ->and($third['is_editing'])->toBeTrue()
        ->and($third['weeks'][0]['label'])->toBe('Week 4 · Sep 28');
});

it('SCH-060 PLAN-008 a plan week stored inside the 10-day week belongs to that week', function () {
    $sheet = changedPlanAwaySheet(['2026-10-06'], '2026-09-28');

    expect($sheet['is_editing'])->toBeTrue();
});
