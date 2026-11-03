<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\SwapWorkoutExercise;
use App\Capabilities\Exercises\ExerciseReads;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\ExerciseSwapInput;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ExerciseSwapCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly SwapWorkoutExercise $swap) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('exercise_swap', affects: WorkoutReads::AFFECTS)
            ->description('Swap an exercise of a session under way for another exercise of the same muscle, for this session only or for the rest of the mesocycle. Sets already logged or skipped stay on the old exercise; the rest move to the new one and take its targets. Swap only for a good reason, such as pain or equipment the user cannot get to.')
            ->cli('exercise', 'swap')
            ->input(ExerciseSwapInput::class)
            ->run(function (ExerciseSwapInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $this->ownedOrNew(WorkoutExercise::class, $input->new_workout_exercise_id, $user);
                $rest = $input->scope === 'rest_of_mesocycle';
                $session = ($this->swap)($user, $input->workout_exercise_id, $input->new_workout_exercise_id, ExerciseReads::id($input->exercise_id), $rest);

                return new WriteResult(invalidates: WorkoutDayReads::keys($user, $session, $rest ? $session->week : null));
            });
    }

    public function owned(User $user): array
    {
        return [
            'workout_exercise_id' => WorkoutExercise::query()->where('user_id', $user->id),
            'exercise_id' => Exercise::query()->visibleTo($user),
        ];
    }
}
