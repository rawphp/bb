<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\SkipWorkout;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\TodayReads;
use App\Capabilities\Workout\Data\WorkoutSkipInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutSkipCapability implements HostCapability
{
    public function __construct(private readonly SkipWorkout $skip) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // WO-050, AGT-010: it can't be undone, so the user confirms it first.
        return HostDefinition::write('workout_skip', affects: [...WorkoutReads::AFFECTS, ...TodayReads::SHEETS], destructive: true)
            ->description('Skip a whole session, started or not; it cannot be undone, so ask the user to confirm first. A session not started is saved skipped with no sets, and one under way keeps what was logged. When the session is today or later, its day becomes a rest day and the unchecked meals are re-targeted.')
            ->cli('workout', 'skip')
            ->input(WorkoutSkipInput::class)
            ->run(function (WorkoutSkipInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $skipped = ($this->skip)($user, $input->workout_id);

                return new WriteResult(
                    invalidates: array_values(array_unique([
                        // A skipped session can no longer move, so Today's Add workout sheet stops offering it.
                        ...WorkoutReads::keys($user, $skipped['session'], planWeek: true),
                        // SCH-053: the day whose meals changed, and the sheets that read it.
                        ...TodayReads::keys($skipped['dates'], TodayReads::SHEETS),
                    ])),
                    toast: $skipped['toast'],
                );
            });
    }
}
