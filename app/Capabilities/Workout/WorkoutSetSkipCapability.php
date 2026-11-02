<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\SkipWorkoutSet;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutSetSkipInput;
use App\Models\User;
use App\Models\WorkoutSet;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutSetSkipCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SkipWorkoutSet $skip) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_set_skip', affects: ['workout_show', 'today_show', 'train_show', 'mesocycle_show'])
            ->description('Skip or unskip a set in a session under way. A skipped set stays on the screen, greyed, is unlogged and never counts as a working set.')
            ->cli('workout', 'set-skip')
            ->input(WorkoutSetSkipInput::class)
            ->run(function (WorkoutSetSkipInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: WorkoutReads::keys(($this->skip)($user, $input->set_id, $input->is_skipped)));
            });
    }

    public function owned(User $user): array
    {
        return ['set_id' => WorkoutSet::query()->where('user_id', $user->id)];
    }
}
