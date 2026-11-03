<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\RemoveWorkoutExercise;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutExerciseRemoveInput;
use App\Models\User;
use App\Models\WorkoutExercise;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutExerciseRemoveCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RemoveWorkoutExercise $remove) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes the exercise's sets, logged ones too.
        return HostDefinition::write('workout_exercise_remove', affects: WorkoutReads::AFFECTS, destructive: true)
            ->description('Remove an exercise and all its sets, logged ones included, from a session under way. Later sessions still plan it. It cannot be undone.')
            ->cli('workout', 'exercise-remove')
            ->input(WorkoutExerciseRemoveInput::class)
            ->run(function (WorkoutExerciseRemoveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: WorkoutReads::keys($user, ($this->remove)($user, $input->workout_exercise_id)));
            });
    }

    public function owned(User $user): array
    {
        return ['workout_exercise_id' => WorkoutExercise::query()->where('user_id', $user->id)];
    }
}
