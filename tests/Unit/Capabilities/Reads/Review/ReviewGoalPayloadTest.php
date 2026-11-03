<?php

use App\Capabilities\Reads\Review\ReviewGoalPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ReviewShowWorld;

// Step 5 of review_show: keep the goal, choose a new one or end the plan (rp-clone goalOptions and ReviewGoalStep).

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function rvGoal(?Closure $change = null): array
{
    return ReviewGoalPayload::build(ReviewShowWorld::analysis($change))->toArray();
}

it('REV-003 the goal step offers to keep the goal, choose a new one or end the plan, recommending to keep', function () {
    $payload = rvGoal();

    expect($payload['notes'])->toBe([])
        ->and($payload['options'])->toBe([
            ['id' => 'keep_goal', 'title' => 'Keep current goal', 'detail' => '', 'is_recommended' => true, 'is_selected' => true, 'is_enabled' => true],
            ['id' => 'choose_new_goal', 'title' => 'Choose a new goal', 'detail' => '', 'is_recommended' => false, 'is_selected' => false, 'is_enabled' => true],
            ['id' => 'end_plan', 'title' => 'End the plan', 'detail' => '', 'is_recommended' => false, 'is_selected' => false, 'is_enabled' => true],
        ]);
});

it('PH-041 the final review lets the user keep the goal and extend the phase up to the TBL-010 maximum', function () {
    // The 4-week cut ends with the reviewed week; the maximum is 16 weeks.
    $payload = rvGoal(fn (ReviewSeed $seed) => $seed->phaseEndDate = '2026-10-12');

    expect(array_column($payload['options'], 'id'))->toBe(['keep_goal', 'choose_new_goal', 'end_plan'])
        ->and($payload['options'][0]['detail'])->toBe('Extend the phase by up to 12 weeks');
});

it('PH-041 a review before the end date keeps the goal without an extension', function () {
    $payload = rvGoal(fn (ReviewSeed $seed) => $seed->phaseEndDate = '2026-11-02');

    expect($payload['options'][0]['detail'])->toBe('');
});

it('REV-043 fatigue that stays high after a diet break and a deload advises ending fat loss on the goal step', function () {
    // Plan weeks 0 to 3 of the phase: high, a deload diet break, a diet break, high (the week reviewed).
    $payload = rvGoal(function (ReviewSeed $seed) {
        $seed->earlierSessionDates = ['2026-09-14'];
        $seed->planWeekRows = [
            ['start_date' => '2026-09-21', 'is_deload' => true, 'is_diet_break' => true],
            ['start_date' => '2026-09-28', 'is_diet_break' => true],
        ];
        $seed->muscleFeedback = [
            ...ReviewSeed::highFatigue(ReviewSeed::earlierKey(0)),
            ...ReviewSeed::highFatigue(ReviewSeed::sessionKey(1)),
        ];
    });

    expect($payload['notes'])->toBe([[
        'text' => 'Your training fatigue stayed high after a diet break and a deload. Consider ending fat loss and moving to Maintenance.',
        'tone' => 'warning',
    ]]);
});

it('REV-043 has no end-fat-loss advice for a calm week, or for high fatigue with no break before it', function () {
    $high = rvGoal(fn (ReviewSeed $seed) => $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1)));

    expect(rvGoal()['notes'])->toBe([])
        ->and($high['notes'])->toBe([]);
});
