<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\ReorderWorkoutExercise;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutExerciseReorderInput;
use App\Models\User;
use App\Models\WorkoutExercise;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutExerciseReorderCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly ReorderWorkoutExercise $reorder) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_exercise_reorder', affects: WorkoutReads::AFFECTS)
            ->description('Move an exercise of a session under way to a new place, counted from 1, as Move up, Move down and Reorder exercises do. With remaining_weeks true the day\'s exercises take the same order in the sessions not yet started. Use it to pair exercises back to back.')
            ->cli('workout', 'exercise-reorder')
            ->input(WorkoutExerciseReorderInput::class)
            ->run(function (WorkoutExerciseReorderInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $session = ($this->reorder)($user, $input->workout_exercise_id, $input->position, $input->remaining_weeks);

                return new WriteResult(invalidates: WorkoutDayReads::keys($user, $session, $input->remaining_weeks ? $session->week : null));
            });
    }

    public function owned(User $user): array
    {
        return ['workout_exercise_id' => WorkoutExercise::query()->where('user_id', $user->id)];
    }
}
