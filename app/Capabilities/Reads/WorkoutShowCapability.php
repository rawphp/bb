<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Workout\WorkoutPayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Workout\Data\WorkoutShowInput;
use App\Capabilities\Workout\Data\WorkoutShowOutput;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Workout\WorkoutSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('workout_show')
            ->description('Show one session of a mesocycle by week and day: its exercises, every set\'s target weight, reps and rep table, the feedback questions and when they fall due, the notes and the next meal. Use it before logging, adding or skipping sets, and to look back at an earlier session, which comes back read only. It changes nothing.')
            ->cli('workout', 'show')
            ->input(WorkoutShowInput::class)
            ->output(WorkoutShowOutput::class)
            ->run(function (WorkoutShowInput $input, CapabilityContext $context): WorkoutShowOutput {
                /** @var User $user */
                $user = $context->user();

                return WorkoutPayload::build(WorkoutSnapshot::load($user, $input->mesocycle_id, $input->week, $input->day));
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)];
    }
}
