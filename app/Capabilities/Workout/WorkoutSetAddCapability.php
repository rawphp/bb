<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\AddWorkoutSet;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutSetAddInput;
use App\Domain\Progression\WorkoutSetType;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutSetAddCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly AddWorkoutSet $add) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_set_add', affects: WorkoutReads::AFFECTS)
            ->description('Add a set to an exercise of a session under way, below after_set_id or at the end, as a regular, myorep or myorep match set. It starts from the weight and reps of the set above.')
            ->cli('workout', 'set-add')
            ->input(WorkoutSetAddInput::class)
            ->run(function (WorkoutSetAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $this->ownedOrNew(WorkoutSet::class, $input->set_id, $user);
                $session = ($this->add)($user, $input->set_id, $input->workout_exercise_id, $input->after_set_id, WorkoutSetType::from($input->set_type));

                return new WriteResult(invalidates: WorkoutReads::keys($user, $session));
            });
    }

    public function owned(User $user): array
    {
        return [
            'workout_exercise_id' => WorkoutExercise::query()->where('user_id', $user->id),
            'after_set_id' => WorkoutSet::query()->where('user_id', $user->id),
        ];
    }
}
