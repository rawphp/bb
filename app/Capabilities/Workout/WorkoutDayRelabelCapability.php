<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\RelabelWorkoutDay;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutDayRelabelInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutDayRelabelCapability implements HostCapability
{
    public function __construct(private readonly RelabelWorkoutDay $relabel) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_day_relabel', affects: WorkoutReads::AFFECTS)
            ->description('Rename the day a session belongs to, such as "Upper A" to "Push", in every week of its mesocycle. Use it from a session that is not finished or skipped; it does not start the session.')
            ->cli('workout', 'day-relabel')
            ->input(WorkoutDayRelabelInput::class)
            ->run(function (WorkoutDayRelabelInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $session = ($this->relabel)($user, $input->workout_id, $input->label);

                return new WriteResult(invalidates: WorkoutDayReads::keys($user, $session, 1));
            });
    }
}
