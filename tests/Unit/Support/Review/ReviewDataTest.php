<?php

use App\Domain\Model\MuscleGroup;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\TrainSeed;

// Which sessions ReviewData::load reads: those from the first week the review reads (the calorie weeks, or the first
// week of a phase still running then), and before them each muscle's latest finished sessions a PROG-050 run needs.
// In ReviewSeed the window starts on 2026-09-06, a week before the plan's first week.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));

/** A seed whose chest misses its reps in March and in the one session of the week reviewed (a PROG-050 run). */
function reviewRunSeed(): ReviewSeed
{
    $seed = new ReviewSeed;
    $seed->earlierSessionDates = ['2026-03-02', '2026-03-09', '2026-03-16'];
    $seed->sessionsDone = 1;
    $seed->chestUnderperforms = true;

    return $seed;
}

/**
 * Another session of the user's mesocycle, with four bench press sets that hit their reps.
 *
 * @param  array<string, mixed>  $session
 * @param  array<string, mixed>  $set
 */
function reviewSession(User $user, array $session, array $set = []): WorkoutSession
{
    $mesocycle = Mesocycle::query()->where('user_id', $user->id)->firstOrFail();
    $row = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 1, 'day' => 1, 'planned_end_at' => null, ...$session,
    ]);
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $block = WorkoutExercise::factory()->create(['workout_session_id' => $row->id, 'user_id' => $user->id, 'exercise_id' => $bench->id]);
    foreach (range(1, 4) as $position) {
        WorkoutSet::factory()->create([
            'workout_session_id' => $row->id, 'user_id' => $user->id, 'workout_exercise_id' => $block->id,
            'exercise_id' => $bench->id, 'position' => $position, 'target_weight_kg' => 80, 'target_reps' => 10,
            'actual_weight_kg' => 80, 'actual_reps' => 10, 'is_logged' => true, ...$set,
        ]);
    }

    return $row;
}

/** @return list<string> */
function reviewLoadedSessions(User $user): array
{
    return ReviewData::load($user, ReviewSeed::TODAY)->sessions->modelKeys();
}

it('REV-040 PROG-050 the look-back reads only the user\'s sessions, never another user\'s newer ones', function () {
    // Made first, with chest sessions after the user's March ones and still before the window: an unscoped look-back
    // would rank them first and push the user's out.
    $stranger = new ReviewSeed;
    $stranger->earlierSessionDates = ['2026-04-06', '2026-04-13', '2026-04-20'];
    $other = $stranger->seed();
    $seed = reviewRunSeed();
    $user = $seed->seed();

    $loaded = reviewLoadedSessions($user);
    $analysis = ReviewAnalysis::fromData(ReviewData::load($user, ReviewSeed::TODAY)) ?? throw new RuntimeException('No review is open.');

    expect($loaded)->toContain($seed->sessionIds[ReviewSeed::earlierKey(1)])
        ->and($loaded)->toContain($seed->sessionIds[ReviewSeed::earlierKey(2)])
        ->and(array_intersect($loaded, WorkoutSession::query()->where('user_id', $other->id)->pluck('id')->all()))->toBe([])
        ->and($analysis->training->underperformingMuscles)->toBe([MuscleGroup::Chest]);
});

it('REV-040 PROG-050 the look-back counts only finished sessions with logged sets that have their reps', function () {
    $cases = [
        'skipped' => [['is_skipped' => true, 'actual_start_at' => '2026-03-23 17:30:00', 'actual_end_at' => '2026-03-23 18:45:00'], []],
        'unfinished' => [['actual_start_at' => '2026-03-23 17:30:00', 'actual_end_at' => null], []],
        'sets not logged' => [['actual_start_at' => '2026-03-23 17:30:00', 'actual_end_at' => '2026-03-23 18:45:00'], ['is_logged' => false]],
        'sets skipped' => [['actual_start_at' => '2026-03-23 17:30:00', 'actual_end_at' => '2026-03-23 18:45:00'], ['is_skipped' => true]],
        'no reps' => [['actual_start_at' => '2026-03-23 17:30:00', 'actual_end_at' => '2026-03-23 18:45:00'], ['actual_reps' => null]],
        'no target' => [['actual_start_at' => '2026-03-23 17:30:00', 'actual_end_at' => '2026-03-23 18:45:00'], ['target_reps' => null]],
    ];
    foreach ($cases as $name => [$session, $set]) {
        $seed = reviewRunSeed();
        $user = $seed->seed();
        // Newer than the March sessions, so it would take a look-back place if it counted.
        $extra = reviewSession($user, ['planned_start_at' => '2026-03-23 17:30:00', ...$session], $set);

        $loaded = reviewLoadedSessions($user);

        $read = fn (string $id): bool => in_array($id, $loaded, true);
        expect([
            'second latest' => $read($seed->sessionIds[ReviewSeed::earlierKey(1)]),
            'latest' => $read($seed->sessionIds[ReviewSeed::earlierKey(2)]),
            'oldest' => $read($seed->sessionIds[ReviewSeed::earlierKey(0)]),
            'extra' => $read($extra->id),
        ])->toBe(['second latest' => true, 'latest' => true, 'oldest' => false, 'extra' => false], $name);
    }
});

it('REV-040 a session with neither a planned nor an actual start is still read', function () {
    $user = (new ReviewSeed)->seed();
    $undated = reviewSession($user, ['planned_start_at' => null, 'actual_start_at' => null, 'actual_end_at' => null]);

    expect(reviewLoadedSessions($user))->toContain($undated->id);
});

it('REV-040 the window reaches back to the first week of a phase that started before the calorie weeks', function () {
    // Twenty finished weeks: the calorie weeks start in July, the phase on 2026-05-25. A skipped session in its first
    // week counts toward that week's fatigue; one three weeks before the phase does not.
    $seed = new ReviewSeed;
    $seed->weeksFinished = 20;
    $user = $seed->seed();
    $inPhase = reviewSession($user, ['is_skipped' => true, 'planned_start_at' => '2026-05-27 17:30:00']);
    $before = reviewSession($user, ['is_skipped' => true, 'planned_start_at' => '2026-05-04 17:30:00']);

    $loaded = reviewLoadedSessions($user);

    expect($seed->planStart())->toBe('2026-05-25')
        ->and($loaded)->toContain($inPhase->id)
        ->and($loaded)->not->toContain($before->id);
});
