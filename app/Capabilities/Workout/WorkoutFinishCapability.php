<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\FinishWorkout;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\TodayReads;
use App\Capabilities\Workout\Data\WorkoutFinishInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutFinishCapability implements HostCapability
{
    public function __construct(private readonly FinishWorkout $finish) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: Finish changes next week's sets and may re-target today's meals, so it comes with Undo.
        return HostDefinition::write('workout_finish', affects: [...WorkoutReads::AFFECTS, 'day_adjust_show', 'day_redistribute_show'], reversible: true)
            ->description('Finish a started workout, recording when it ended. Use it once every set is logged or skipped and every due feedback sheet is answered. It works out next week\'s sets for that day from the feedback, and re-targets today\'s remaining meals when the session ran more than 20 minutes longer or shorter than planned.')
            ->cli('workout', 'finish')
            ->input(WorkoutFinishInput::class)
            ->run(function (WorkoutFinishInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                // The request clock, by the instructor's decision on #84. Swapping in OccurredAt::get() (#195, now on main),
                // the instant the user finished, is a one-line follow-up in #195's scope, so offline replays keep the time.
                $finished = ($this->finish)($user, $input->workout_id, CarbonImmutable::now());
                $session = $finished['session'];

                return new WriteResult(
                    invalidates: [
                        ...WorkoutReads::keys($user, $session),
                        // SCH-054: the days whose meals changed, and the sheets that read them.
                        ...TodayReads::keys($finished['dates'], ['day_adjust_show', 'day_redistribute_show']),
                        ...TodayReads::weekKeys($finished['week']),
                    ],
                    toast: $finished['toast'],
                );
            });
    }
}
