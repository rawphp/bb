<?php

namespace App\Actions\Workout;

use App\Capabilities\Workout\Data\WorkoutFeedbackAnswerInput;
use App\Domain\Model\MuscleGroup;
use App\Domain\Progression\FeedbackSheet;
use App\Domain\Progression\WorkoutStatus;
use App\Models\ExerciseFeedback;
use App\Models\MuscleFeedback;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\NextWeekSets;
use App\Support\Workout\WorkoutSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FB-001 to FB-009: saves one muscle's Feedback sheet of the session a workout id names (OFF-021), or changes it until
 * the muscle trains again. Each row is created the first time and updated after, and only what changed is written.
 * Once the session is finished, a change works next week's sets out again from the session as it ran (FB-009,
 * PROG-019), so the same answers give the same counts. The sheet is refused when the session would not offer it.
 */
final class AnswerWorkoutFeedback
{
    public const NOT_STARTED = 'Start this workout before answering its feedback.';

    public const DELOAD = 'No feedback is asked in a deload week.';

    /** @return array{toast: ?string, session: WorkoutSession} the UI-010 lines when next week's sets changed, and the session */
    public function __invoke(User $user, WorkoutFeedbackAnswerInput $input): array
    {
        $snapshot = WorkoutSnapshot::forWorkout($user, $input->workout_id);
        $session = $snapshot->session();
        $status = $snapshot->status();
        $muscle = MuscleGroup::from($input->muscle_group);

        if ($session === null || $status === WorkoutStatus::NotStarted || $status === WorkoutStatus::Skipped) {
            throw new DomainException(self::NOT_STARTED);
        }
        if ($snapshot->isDeload()) {
            throw new DomainException(self::DELOAD);
        }
        $sheet = array_find($snapshot->sheets(), fn (FeedbackSheet $sheet) => $sheet->muscle === $muscle);
        if ($sheet === null || ! $sheet->isDue) {
            throw new DomainException("{$muscle->title()} feedback isn't due yet. Log or skip every set of its last exercise first.");
        }
        $finished = $status === WorkoutStatus::Finished;
        if ($finished && $snapshot->isLocked($muscle)) {
            throw new DomainException("You've trained your ".mb_strtolower($muscle->title()).' since this workout, so its feedback can\'t change now.');
        }
        $asksSoreness = $snapshot->asksSoreness($muscle);
        if ($asksSoreness && $input->soreness === null) {
            throw new DomainException('Say how sore your '.mb_strtolower($muscle->title()).' got after you last trained them.');
        }
        $pain = $this->jointPain($input, $sheet);

        return DB::transaction(function () use ($user, $snapshot, $session, $input, $muscle, $asksSoreness, $pain, $finished): array {
            $row = $snapshot->muscleAnswers($muscle) ?? (new MuscleFeedback([
                'workout_session_id' => $session->id, 'muscle_group' => $muscle,
            ]))->forceFill(['user_id' => $user->id]);
            $row->fill(['soreness' => $asksSoreness ? $input->soreness : null, 'pump' => $input->pump, 'volume' => $input->volume]);
            $wrote = $row->isDirty() || ! $row->exists;
            $row->save();

            foreach ($pain as $exerciseId => $answer) {
                $stored = $snapshot->exerciseFeedback->first(fn (ExerciseFeedback $f) => $f->workout_session_id === $session->id && (int) $f->exercise_id === $exerciseId)
                    ?? (new ExerciseFeedback(['workout_session_id' => $session->id, 'exercise_id' => $exerciseId]))->forceFill(['user_id' => $user->id]);
                $stored->joint_pain = $answer;
                $wrote = $wrote || $stored->isDirty() || ! $stored->exists;
                $stored->save();
            }

            // FB-009: next week follows the new answers, read back from what was just saved.
            $toast = $wrote && $finished
                ? NextWeekSets::save(WorkoutSnapshot::load($user, (string) $snapshot->mesocycle->id, $snapshot->week, $snapshot->day))
                : null;

            return ['toast' => $toast, 'session' => $session];
        });
    }

    /**
     * FB-002: one answer for each exercise the sheet asks about, and none for any other.
     *
     * @return array<int, string> the answers by library exercise id
     */
    private function jointPain(WorkoutFeedbackAnswerInput $input, FeedbackSheet $sheet): array
    {
        $answers = [];
        foreach ($input->joint_pain as $answer) {
            $id = filter_var($answer->exercise_id, FILTER_VALIDATE_INT);
            if ($id === false || ! isset($sheet->exercises[$id])) {
                throw new DomainException('That exercise isn\'t part of this muscle\'s feedback. Open the sheet again.');
            }
            $answers[$id] = $answer->answer;
        }
        foreach ($sheet->exercises as $id => $name) {
            if (! isset($answers[$id])) {
                throw new DomainException("Say how your joints felt during {$name}.");
            }
        }

        return $answers;
    }
}
