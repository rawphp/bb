<?php

use App\Actions\Workout\AnswerWorkoutFeedback;
use App\Actions\Workout\FinishWorkout;
use App\Capabilities\Workout\Data\WorkoutFeedbackAnswerInput;
use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// The action's job is what it saves, so it runs on the database. Week 3 of Meso 2 (muscle gain): Upper A on Monday 21
// September, Bench Press for chest. The rules are FeedbackSheetsTest and ProgressionApplyTest.

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle, WorkoutSession} */
function feedbackSession(?int $reps = 10, string $phase = 'muscleGain'): array
{
    Carbon::setTestNow('2026-09-21 18:30:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, $phase, '2026-08-31')->id]);

    return [$user, $mesocycle, WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00', $reps)];
}

function chestAnswer(WorkoutSession $session, Mesocycle $mesocycle, array $input = []): WorkoutFeedbackAnswerInput
{
    $joint = $input['joint_pain'] ?? [['exercise_id' => (string) WorkoutSeed::exerciseId($mesocycle, 1), 'answer' => 'none']];
    unset($input['joint_pain']);

    return WorkoutFeedbackAnswerInput::from([
        'workout_id' => WorkoutSeed::id($session), 'muscle_group' => 'chest', 'pump' => 'low', 'volume' => 'notEnough', ...$input,
        'joint_pain' => $joint,
    ]);
}

function answerRefusal(User $user, WorkoutFeedbackAnswerInput $input): ?string
{
    try {
        app(AnswerWorkoutFeedback::class)($user, $input);
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }

    return null;
}

it('FB-001 FB-002 FB-010 saving writes one muscle row and one row per exercise, kept for the weekly review', function () {
    [$user, $mesocycle, $session] = feedbackSession();

    $result = app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle));
    $muscle = MuscleFeedback::query()->sole();

    expect($result['toast'])->toBeNull()
        ->and([$muscle->user_id, $muscle->workout_session_id, $muscle->muscle_group->value, $muscle->soreness, $muscle->pump, $muscle->volume])
        ->toBe([$user->id, $session->id, 'chest', null, 'low', 'notEnough'])
        ->and(ExerciseFeedback::query()->sole()->only(['user_id', 'exercise_id', 'joint_pain']))
        ->toBe(['user_id' => $user->id, 'exercise_id' => WorkoutSeed::exerciseId($mesocycle, 1), 'joint_pain' => 'none']);
});

it('FB-001 refuses a muscle whose last exercise still has a set to do', function () {
    [$user, $mesocycle, $session] = feedbackSession(reps: null);

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))
        ->toBe('Chest feedback isn\'t due yet. Log or skip every set of its last exercise first.')
        ->and(MuscleFeedback::query()->count())->toBe(0);
});

it('FB-001 a skipped set counts as done', function () {
    [$user, $mesocycle, $session] = feedbackSession(reps: null);
    WorkoutSet::query()->update(['is_skipped' => true]);

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))->toBeNull();
});

it('FB-002 refuses an answer that leaves out an exercise of the muscle, or names one outside it', function () {
    [$user, $mesocycle, $session] = feedbackSession();
    $squat = (string) WorkoutSeed::exerciseId($mesocycle, 2);

    expect(answerRefusal($user, chestAnswer($session, $mesocycle, ['joint_pain' => []])))->toBe('Say how your joints felt during Bench Press.')
        ->and(answerRefusal($user, chestAnswer($session, $mesocycle, ['joint_pain' => [['exercise_id' => $squat, 'answer' => 'none']]])))
        ->toBe('That exercise isn\'t part of this muscle\'s feedback. Open the sheet again.')
        ->and(MuscleFeedback::query()->count())->toBe(0);
});

it('FB-007 needs soreness when the muscle trained in the last two weeks, and drops it when it is not asked', function () {
    [$user, $mesocycle, $session] = feedbackSession();

    expect(answerRefusal($user, chestAnswer($session, $mesocycle, ['soreness' => 'stillSore'])))->toBeNull()
        ->and(MuscleFeedback::query()->sole()->soreness)->toBeNull();

    TrainSeed::trained($mesocycle, 2, 1, '2026-09-14');

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))->toBe('Say how sore your chest got after you last trained them.')
        ->and(answerRefusal($user, chestAnswer($session, $mesocycle, ['soreness' => 'stillSore'])))->toBeNull()
        ->and(MuscleFeedback::query()->where('workout_session_id', $session->id)->sole()->soreness)->toBe('stillSore');
});

it('FB-008 refuses feedback in a deload week', function () {
    Carbon::setTestNow('2026-10-05 18:30:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, 5, 1, '2026-10-05 17:30:00');

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))->toBe(AnswerWorkoutFeedback::DELOAD);
});

it('FB-001 refuses a session that has not started or was skipped', function () {
    [$user, $mesocycle, $session] = feedbackSession();
    $session->forceFill(['is_skipped' => true])->save();

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))->toBe(AnswerWorkoutFeedback::NOT_STARTED);

    $session->forceFill(['is_skipped' => false, 'actual_start_at' => null])->save();

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))->toBe(AnswerWorkoutFeedback::NOT_STARTED)
        ->and(MuscleFeedback::query()->count())->toBe(0);
});

it('FB-009 saving again updates the rows that changed and keeps their ids', function () {
    [$user, $mesocycle, $session] = feedbackSession();
    app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle));
    $ids = [MuscleFeedback::query()->sole()->id, ExerciseFeedback::query()->sole()->id];

    app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle, [
        'volume' => 'tooMuch', 'joint_pain' => [['exercise_id' => (string) WorkoutSeed::exerciseId($mesocycle, 1), 'answer' => 'aLot']],
    ]));

    expect([MuscleFeedback::query()->sole()->id, ExerciseFeedback::query()->sole()->id])->toBe($ids)
        ->and(MuscleFeedback::query()->sole()->volume)->toBe('tooMuch')
        ->and(ExerciseFeedback::query()->sole()->joint_pain)->toBe('aLot');
});

it('FB-009 PROG-019 an edit after finish works next week out again from the same base and shows the toast', function () {
    [$user, $mesocycle, $session] = feedbackSession();
    app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle, ['pump' => 'moderate', 'volume' => 'justRight']));
    app(FinishWorkout::class)($user, WorkoutSeed::id($session), Carbon::now());

    expect($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe(3);

    $result = app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle));

    expect($result['toast'])->toBe('Chest +2 sets next week')
        ->and($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe(5)
        ->and(app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle))['toast'])->toBeNull()
        ->and($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe(5);
});

it('FB-009 refuses a change after finish once a later session has trained the muscle, and keeps the stored answers', function () {
    [$user, $mesocycle, $session] = feedbackSession();
    app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle, ['pump' => 'moderate', 'volume' => 'justRight']));
    app(FinishWorkout::class)($user, WorkoutSeed::id($session), Carbon::now());
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28');

    expect(answerRefusal($user, chestAnswer($session, $mesocycle)))
        ->toBe('You\'ve trained your chest since this workout, so its feedback can\'t change now.')
        ->and(MuscleFeedback::query()->where('workout_session_id', $session->id)->sole()->pump)->toBe('moderate')
        ->and($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe(3);
});

it('FB-009 PROG-018 changing only joint pain after finish works next week out again', function () {
    [$user, $mesocycle, $session] = feedbackSession();
    app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle));
    app(FinishWorkout::class)($user, WorkoutSeed::id($session), Carbon::now());

    expect($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe(5);

    $result = app(AnswerWorkoutFeedback::class)($user, chestAnswer($session, $mesocycle, [
        'joint_pain' => [['exercise_id' => (string) WorkoutSeed::exerciseId($mesocycle, 1), 'answer' => 'moderate']],
    ]));

    // Moderate pain keeps added sets off the only chest exercise, so the two added sets come back off.
    expect($result['toast'])->toBe('Chest −2 sets next week')
        ->and($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe(3);
});
