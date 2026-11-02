<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\StartWorkout;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutStartInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutStartCapability implements HostCapability
{
    public function __construct(private readonly StartWorkout $start) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_start', affects: WorkoutReads::AFFECTS)
            ->description('Start a session by the workout_id workout_show gave, recording when it began and writing every planned set with its target. Use it before the first change to a session that has not started; starting one already under way changes nothing. A finished or skipped session cannot be started.')
            ->cli('workout', 'start')
            ->input(WorkoutStartInput::class)
            ->run(function (WorkoutStartInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                // The request clock until #195 lands; then this is OccurredAt::get(), the instant the user acted.
                $session = ($this->start)($user, $input->workout_id, CarbonImmutable::now());

                return new WriteResult(invalidates: WorkoutReads::keys($user, $session));
            });
    }
}
