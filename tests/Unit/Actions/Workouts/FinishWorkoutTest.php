<?php

use App\Actions\Workouts\FinishWorkout;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// The action's job is what it saves, so it runs on the database. Week 3 of Meso 2 (muscle gain): Upper A on Monday 21
// September at 17:30 for 60 minutes, Bench Press for chest (Emphasize). The rules are FeedbackSheetsTest,
// ProgressionApplyTest and the Retarget tests.

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle, WorkoutSession} */
function finishSession(?int $reps = 10, array $answers = ['pump' => 'low', 'volume' => 'notEnough'], int $week = 3, string $date = '2026-09-21'): array
{
    Carbon::setTestNow("{$date} 18:30:00");
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, $week, 1, "{$date} 17:30:00", $reps);
    if ($answers !== []) {
        WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), $answers);
    }

    return [$user, $mesocycle, $session];
}

function finishAt(User $user, WorkoutSession $session, string $at): array|string
{
    try {
        return app(FinishWorkout::class)($user, $session->id, Carbon::parse($at));
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

function benchSets(Mesocycle $mesocycle): int
{
    return $mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'];
}

it('WO-010 records when the session ended, and keeps its start', function () {
    [$user, , $session] = finishSession();

    finishAt($user, $session, '2026-09-21 18:30:00');

    expect($session->fresh()->actual_end_at->format('Y-m-d H:i'))->toBe('2026-09-21 18:30')
        ->and($session->fresh()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:30');
});

it('PROG-010 UI-010 finishing saves the day\'s new slot counts and says so in the toast', function () {
    [$user, $mesocycle, $session] = finishSession();

    $result = finishAt($user, $session, '2026-09-21 18:30:00');

    expect($result['toast'])->toBe('Chest +2 sets next week')
        ->and(benchSets($mesocycle))->toBe(5)
        ->and($mesocycle->fresh()->dayRows()[1])->toBe(TrainSeed::mesocycle($user)->dayRows()[1]);
});

it('PROG-010 finishing with no change writes no plan and shows no toast', function () {
    [$user, $mesocycle, $session] = finishSession(answers: ['pump' => 'moderate', 'volume' => 'justRight']);
    $updated = $mesocycle->fresh()->updated_at;

    expect(finishAt($user, $session, '2026-09-21 18:30:00')['toast'])->toBeNull()
        ->and(benchSets($mesocycle))->toBe(3)
        ->and($mesocycle->fresh()->updated_at)->toEqual($updated);
});

it('PROG-019 sets added or deleted in the session carry forward', function () {
    [$user, $mesocycle, $session] = finishSession(answers: ['pump' => 'moderate', 'volume' => 'justRight']);
    WorkoutSet::query()->orderByDesc('position')->first()->delete();

    expect(finishAt($user, $session, '2026-09-21 18:30:00')['toast'])->toBe('Chest −1 set next week')
        ->and(benchSets($mesocycle))->toBe(2);
});

it('WO-040 refuses to finish while a set is neither logged nor skipped', function () {
    [$user, $mesocycle, $session] = finishSession(reps: null, answers: []);

    expect(finishAt($user, $session, '2026-09-21 18:30:00'))->toBe(FinishWorkout::SETS_LEFT)
        ->and($session->fresh()->actual_end_at)->toBeNull();
});

it('WO-040 refuses to finish while due feedback is unanswered', function () {
    [$user, $mesocycle, $session] = finishSession(answers: []);

    expect(finishAt($user, $session, '2026-09-21 18:30:00'))->toBe('Your feedback is required for chest.')
        ->and($session->fresh()->actual_end_at)->toBeNull()
        ->and(benchSets($mesocycle))->toBe(3);
});

it('WO-010 refuses a session that has not started, or that is finished already', function () {
    [$user, , $session] = finishSession();
    finishAt($user, $session, '2026-09-21 18:30:00');

    expect(finishAt($user, $session, '2026-09-21 18:40:00'))->toBe(FinishWorkout::FINISHED);

    $session->forceFill(['actual_start_at' => null, 'actual_end_at' => null])->save();

    expect(finishAt($user, $session, '2026-09-21 18:40:00'))->toBe(FinishWorkout::NOT_STARTED);
});

it('FB-008 PROG-040 a deload week finishes with no feedback and changes no sets', function () {
    [$user, $mesocycle, $session] = finishSession(answers: [], week: 5, date: '2026-10-05');

    expect(finishAt($user, $session, '2026-10-05 18:30:00')['toast'])->toBeNull()
        ->and($session->fresh()->actual_end_at)->not->toBeNull()
        ->and(benchSets($mesocycle))->toBe(3);
});

it('PROG-050 PROG-051 a muscle that missed its targets two sessions running gains no sets', function () {
    [$user, $mesocycle, $session] = finishSession(reps: 7, answers: ['soreness' => 'healedJustOnTime', 'pump' => 'low', 'volume' => 'notEnough']);
    $earlier = WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);
    $earlier->forceFill(['actual_end_at' => '2026-09-14 18:30:00'])->save();

    expect(finishAt($user, $session, '2026-09-21 18:30:00')['toast'])->toBeNull()
        ->and(benchSets($mesocycle))->toBe(3);
});

it('PROG-050 hitting the targets this session lifts the hold', function () {
    [$user, $mesocycle, $session] = finishSession(answers: ['soreness' => 'healedJustOnTime', 'pump' => 'low', 'volume' => 'notEnough']);
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7);

    expect(benchSets($mesocycle))->toBe(3)
        ->and(finishAt($user, $session, '2026-09-21 18:30:00')['toast'])->toBe('Chest +2 sets next week');
});

it('SCH-054 WO-010 a session that ran more than 20 minutes long re-targets today\'s meals still to come', function () {
    [$user, , $session] = finishSession(answers: ['pump' => 'moderate', 'volume' => 'justRight']);
    $day = SheetSeed::day($user, '2026-09-21');
    $before = $day->fresh()->only(['kcal', 'carbs_g']);

    $result = finishAt($user, $session, '2026-09-21 19:00:00');

    expect($result['toast'])->toBe('Meals updated for your 5:30 PM workout')
        ->and($result['dates'])->toBe(['2026-09-21'])
        ->and($result['week'])->toHaveCount(7)
        ->and(DayTarget::query()->find($day->id)->kcal)->toBeGreaterThan($before['kcal']);
});

it('SCH-054 a session within 20 minutes of plan, or with no targets for the day, leaves the meals alone', function () {
    [$user, , $session] = finishSession(answers: ['pump' => 'moderate', 'volume' => 'justRight']);
    $day = SheetSeed::day($user, '2026-09-21');
    $before = $day->fresh()->only(['kcal', 'carbs_g', 'meals']);

    expect(finishAt($user, $session, '2026-09-21 18:45:00')['dates'])->toBe([])
        ->and(DayTarget::query()->find($day->id)->only(['kcal', 'carbs_g', 'meals']))->toEqual($before);

    [$user, , $session] = finishSession(answers: ['pump' => 'moderate', 'volume' => 'justRight']);

    expect(finishAt($user, $session, '2026-09-21 19:30:00'))->toMatchArray(['toast' => null, 'dates' => []]);
});

it('PROG-019 finishing an older session late leaves the counts a later session of the day already set', function () {
    [$user, $mesocycle, $session] = finishSession();
    $later = WorkoutSeed::started($mesocycle, 4, 1, '2026-09-28 17:30:00');
    $later->forceFill(['actual_end_at' => '2026-09-28 18:30:00'])->save();

    expect(finishAt($user, $session, '2026-09-21 18:30:00')['toast'])->toBeNull()
        ->and(benchSets($mesocycle))->toBe(3);
});

it('SCH-054 a session of an earlier day finished today leaves both days\' meals alone', function () {
    [$user, , $session] = finishSession(answers: ['pump' => 'moderate', 'volume' => 'justRight']);
    $monday = SheetSeed::day($user, '2026-09-21');
    $tuesday = SheetSeed::day($user, '2026-09-22');
    $before = [$monday->fresh()->meals, $tuesday->fresh()->meals];
    Carbon::setTestNow('2026-09-22 09:00:00');

    expect(finishAt($user, $session, '2026-09-22 09:00:00')['dates'])->toBe([])
        ->and([$monday->fresh()->meals, $tuesday->fresh()->meals])->toEqual($before);
});
