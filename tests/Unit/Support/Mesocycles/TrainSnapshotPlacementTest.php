<?php

use App\Models\Mesocycle;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Support\Collection;

// SCH-060: where a saved mesocycle sits once paused away weeks are left out, as Today places it. The plan's weeks start
// on Monday from 2026-08-31 (week 0). Mesocycle A runs 2 weeks from week 0; B is saved at A's raw end, week 2.

function placementTrain(array $planWeeks, bool $withPlan = true): TrainSnapshot
{
    $mesocycle = fn (string $id, string $start, int $weeks) => (new Mesocycle)->forceFill([
        'id' => $id, 'user_id' => 1, 'weeks' => $weeks, 'start_date' => $start, 'is_draft' => false, 'days' => [],
    ]);

    return new TrainSnapshot(
        today: TrainSnapshot::day('2026-09-21'),
        plan: $withPlan ? (new Plan)->forceFill(['user_id' => 1, 'start_date' => '2026-08-31', 'review_weekday' => 1]) : null,
        profile: null,
        mesocycles: new Collection([$mesocycle('meso-a', '2026-08-31', 2), $mesocycle('meso-b', '2026-09-14', 5)]),
        phases: new Collection,
        sessions: new Collection,
        sets: new Collection,
        feedback: new Collection,
        planWeeks: new Collection($planWeeks),
        dayTargets: new Collection,
        exercises: new Collection,
    );
}

function placementAway(string $start, string $training): PlanWeek
{
    return (new PlanWeek)->forceFill([
        'id' => "pw-{$start}", 'user_id' => 1, 'start_date' => $start, 'is_deload' => $training === 'deload',
        'is_diet_break' => false, 'is_away' => true, 'away_training' => $training,
    ]);
}

it('SCH-060 only an away week that pauses training is a paused week', function () {
    $train = placementTrain([placementAway('2026-09-07', 'pause'), placementAway('2026-09-28', 'deload')]);

    expect($train->pausedWeeks())->toBe([1]);
});

it('SCH-060 a pause inside a mesocycle pushes the next one a week later', function () {
    $paused = placementTrain([placementAway('2026-09-07', 'pause')]);
    $plain = placementTrain([]);
    $b = fn (TrainSnapshot $train) => $train->placedStartWeek($train->saved()->firstWhere('id', 'meso-b'));

    expect($b($paused))->toBe(3)
        ->and($b($plain))->toBe(2)
        ->and($paused->placedStartWeek($paused->saved()->firstWhere('id', 'meso-a')))->toBe(0);
});

it('SCH-060 without a plan there is no placement and no paused week', function () {
    $train = placementTrain([placementAway('2026-09-07', 'pause')], withPlan: false);

    expect($train->placedStartWeek($train->saved()->first()))->toBeNull()
        ->and($train->pausedWeeks())->toBe([]);
});
