<?php

namespace App\Actions\Workouts;

use App\Capabilities\Workout\Data\WorkoutFeedbackAnswerInput;
use App\Capabilities\Workout\Data\WorkoutJointPainAnswer;
use App\Domain\Model\MuscleGroup;
use App\Domain\Workout\SessionExercise;
use App\Models\ExerciseFeedback;
use App\Models\MuscleFeedback;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workouts\NextWeekSets;
use App\Support\Workouts\WorkoutSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FB-001 to FB-009: saves one muscle's Feedback sheet, or changes it until the muscle trains again. Each row is
 * created the first time and updated after, and only what changed is written. Once the session is finished, a change
 * works next week's sets out again from the session as it ran (FB-009, PROG-019), so the same answers give the same
 * counts. The sheet is refused when the session would not offer it.
 */
final class AnswerWorkoutFeedback
{
    public const NOT_STARTED = 'Start this workout before answering its feedback.';

    public const DELOAD = 'No feedback is asked in a deload week.';

    /** @return array{toast: ?string, session: WorkoutSession} the UI-010 lines when next week's sets changed, and the session */
    public function __invoke(User $user, WorkoutFeedbackAnswerInput $input): array
    {
        $snapshot = WorkoutSnapshot::load($user, $input->workout_id);
        $session = $snapshot->session;
        $muscle = MuscleGroup::from($input->muscle_group);
        $sheets = $snapshot->sheets();

        if (! $snapshot->isStarted() || $session->is_skipped) {
            throw new DomainException(self::NOT_STARTED);
        }
        if ($sheets->isDeload) {
            throw new DomainException(self::DELOAD);
        }
        if (! $sheets->isDue($muscle)) {
            throw new DomainException("{$muscle->title()} feedback isn't due yet. Log or skip every set of its last exercise first.");
        }
        if ($snapshot->isFinished() && $sheets->isLocked($muscle)) {
            throw new DomainException("You've trained your ".strtolower($muscle->title()).' since this workout, so its feedback can\'t change now.');
        }
        $asksSoreness = $sheets->asksSoreness($muscle);
        if ($asksSoreness && $input->soreness === null) {
            throw new DomainException('Say how sore your '.strtolower($muscle->title()).' got after you last trained them.');
        }
        $pain = $this->jointPain($input, $sheets->asked($muscle));

        $wrote = DB::transaction(function () use ($user, $snapshot, $input, $muscle, $asksSoreness, $pain): bool {
            $row = $snapshot->muscleFeedback->get($muscle->value) ?? (new MuscleFeedback([
                'workout_session_id' => $snapshot->session->id, 'muscle_group' => $muscle,
            ]))->forceFill(['user_id' => $user->id]);
            $row->fill([
                'soreness' => $asksSoreness ? $input->soreness : null,
                'pump' => $input->pump,
                'volume' => $input->volume,
            ]);
            $wrote = $row->isDirty() || ! $row->exists;
            $row->save();
            $snapshot->muscleFeedback->put($muscle->value, $row);

            foreach ($pain as $exerciseId => $answer) {
                $stored = $snapshot->exerciseFeedback->get($exerciseId) ?? (new ExerciseFeedback([
                    'workout_session_id' => $snapshot->session->id, 'exercise_id' => $exerciseId,
                ]))->forceFill(['user_id' => $user->id]);
                $stored->joint_pain = $answer;
                $wrote = $wrote || $stored->isDirty() || ! $stored->exists;
                $stored->save();
                $snapshot->exerciseFeedback->put($exerciseId, $stored);
            }

            return $wrote;
        });

        $toast = null;
        if ($wrote && $snapshot->isFinished()) {
            $toast = NextWeekSets::save($snapshot);
        }

        return ['toast' => $toast, 'session' => $snapshot->session];
    }

    /**
     * FB-002: one answer for each exercise of the muscle in the session, and none for any other.
     *
     * @param  list<SessionExercise>  $asked
     * @return array<int, string> the answers by library exercise id
     */
    private function jointPain(WorkoutFeedbackAnswerInput $input, array $asked): array
    {
        $answers = [];
        foreach ($input->joint_pain as $answer) {
            /** @var WorkoutJointPainAnswer $answer */
            $id = filter_var($answer->exercise_id, FILTER_VALIDATE_INT);
            if ($id === false || ! array_any($asked, fn (SessionExercise $exercise) => $exercise->exerciseId === $id)) {
                throw new DomainException('That exercise isn\'t part of this muscle\'s feedback. Open the sheet again.');
            }
            $answers[$id] = $answer->answer;
        }
        foreach ($asked as $exercise) {
            if (! isset($answers[$exercise->exerciseId])) {
                throw new DomainException("Say how your joints felt during {$exercise->name}.");
            }
        }

        return $answers;
    }
}
