<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\DeleteWorkoutSet;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutSetDeleteInput;
use App\Models\User;
use App\Models\WorkoutSet;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutSetDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteWorkoutSet $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes what the user logged, so the coach needs the user's say-so.
        return HostDefinition::write('workout_set_delete', affects: ['workout_show', 'today_show', 'train_show', 'mesocycle_show'], destructive: true)
            ->description('Delete a set from a session under way, with whatever was logged on it; it cannot be undone. Use skip instead when the user only did not do the set. Removed sets drop from later weeks once the session is finished.')
            ->cli('workout', 'set-delete')
            ->input(WorkoutSetDeleteInput::class)
            ->run(function (WorkoutSetDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: WorkoutReads::keys(($this->delete)($user, $input->set_id)));
            });
    }

    public function owned(User $user): array
    {
        return ['set_id' => WorkoutSet::query()->where('user_id', $user->id)];
    }
}
