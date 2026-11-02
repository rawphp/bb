<?php

namespace App\Capabilities\Workout;

use App\Actions\Workouts\AnswerWorkoutFeedback;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutFeedbackAnswerInput;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutSession;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutFeedbackAnswerCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly AnswerWorkoutFeedback $answer) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: after Finish a change re-works next week's sets, an automatic change with Undo.
        return HostDefinition::write('workout_feedback_answer', affects: ['train_show', 'mesocycle_show'], reversible: true)
            ->description('Save one muscle\'s post-workout feedback: joint pain for each of its exercises in the session, pump, volume, and soreness when the sheet asks it. Use it once every set of that muscle\'s last exercise is logged or skipped, or to change an answer until the muscle trains again. After the workout is finished, a change re-works next week\'s sets for that day.')
            ->cli('workout', 'feedback')
            ->input(WorkoutFeedbackAnswerInput::class)
            ->run(function (WorkoutFeedbackAnswerInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $answered = ($this->answer)($user, $input);

                return new WriteResult(invalidates: WorkoutReads::keys($user, $answered['session']), toast: $answered['toast']);
            });
    }

    public function owned(User $user): array
    {
        return [
            'workout_id' => WorkoutSession::query()->where('user_id', $user->id),
            // FB-002: a library exercise, or one of the user's own.
            'joint_pain.*.exercise_id' => Exercise::query()->visibleTo($user),
        ];
    }
}
