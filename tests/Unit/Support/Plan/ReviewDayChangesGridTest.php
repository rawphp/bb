<?php

use App\Models\Phase;
use App\Models\Plan;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Review\ReviewPlanRebuild;
use App\Support\Today\AdjustWeek;
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
