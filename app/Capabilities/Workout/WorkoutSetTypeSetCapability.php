<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\SetWorkoutSetType;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutSetTypeSetInput;
use App\Domain\Progression\WorkoutSetType;
use App\Models\User;
use App\Models\WorkoutSet;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutSetTypeSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetWorkoutSetType $setType) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_set_type_set', affects: WorkoutReads::AFFECTS)
            ->description('Change a set\'s type in a session under way: regular (straight, down or ascending), myorep or myorep match. For myoreps the user rests 5 to 15 seconds between mini-sets and logs the total reps; for myorep match they do mini-sets until they match the first set\'s reps.')
            ->cli('workout', 'set-type')
            ->input(WorkoutSetTypeSetInput::class)
            ->run(function (WorkoutSetTypeSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: WorkoutReads::keys($user, ($this->setType)($user, $input->set_id, WorkoutSetType::from($input->set_type))));
            });
    }

    public function owned(User $user): array
    {
        return ['set_id' => WorkoutSet::query()->where('user_id', $user->id)];
    }
}
