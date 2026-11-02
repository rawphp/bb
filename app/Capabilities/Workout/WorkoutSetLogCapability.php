<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\LogWorkoutSet;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutSetLogInput;
use App\Domain\Model\WeightUnit;
use App\Models\User;
use App\Models\WorkoutSet;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutSetLogCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly LogWorkoutSet $log) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_set_log', affects: ['workout_show', 'today_show', 'train_show', 'mesocycle_show'])
            ->description('Save a set\'s weight and reps in a session under way, and tick or untick Log; a logged set counts as a working set. Use it after the user lifts, with the weight in the unit they typed (one dumbbell\'s weight for dumbbells; for bodyweight, the added weight or none). Logging needs the reps, and the weight unless the exercise is bodyweight.')
            ->cli('workout', 'set-log')
            ->input(WorkoutSetLogInput::class)
            ->run(function (WorkoutSetLogInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $session = ($this->log)(
                    $user,
                    $input->set_id,
                    $input->weight?->value,
                    $input->weight === null ? null : WeightUnit::from($input->weight->unit),
                    $input->reps,
                    $input->is_logged,
                );

                return new WriteResult(invalidates: WorkoutReads::keys($session));
            });
    }

    public function owned(User $user): array
    {
        return ['set_id' => WorkoutSet::query()->where('user_id', $user->id)];
    }
}
