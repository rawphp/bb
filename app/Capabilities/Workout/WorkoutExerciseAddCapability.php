<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\AddWorkoutExercise;
use App\Capabilities\Exercises\ExerciseReads;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutExerciseAddInput;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutExerciseAddCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly AddWorkoutExercise $add) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_exercise_add', affects: WorkoutReads::AFFECTS)
            ->description('Add an exercise to the end of a session under way, with three regular sets targeted from its history. With remaining_weeks true the day also gets it, as three sets, for the sessions not yet started. Start the session with workout_start first.')
            ->cli('workout', 'exercise-add')
            ->input(WorkoutExerciseAddInput::class)
            ->run(function (WorkoutExerciseAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $this->ownedOrNew(WorkoutExercise::class, $input->workout_exercise_id, $user);
                $session = ($this->add)($user, $input->workout_id, $input->workout_exercise_id, ExerciseReads::id($input->exercise_id), $input->remaining_weeks);

                return new WriteResult(invalidates: WorkoutDayReads::keys($user, $session, $input->remaining_weeks ? $session->week : null));
            });
    }

    public function owned(User $user): array
    {
        return ['exercise_id' => Exercise::query()->visibleTo($user)];
    }
}
