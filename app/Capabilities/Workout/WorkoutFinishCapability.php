<?php

namespace App\Capabilities\Workout;

use App\Actions\Workouts\FinishWorkout;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\TodayReads;
use App\Capabilities\Workout\Data\WorkoutFinishInput;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutFinishCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly FinishWorkout $finish) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: Finish changes next week's sets and may re-target today's meals, so it comes with Undo.
        return HostDefinition::write('workout_finish', affects: ['today_show', 'week_show', 'train_show', 'mesocycle_show', 'day_adjust_show', 'day_redistribute_show'], reversible: true)
            ->description('Finish a started workout, recording when it ended. Use it once every set is logged or skipped and every due feedback sheet is answered. It works out next week\'s sets for that day from the feedback, and re-targets today\'s remaining meals when the session ran more than 20 minutes longer or shorter than planned.')
            ->cli('workout', 'finish')
            ->input(WorkoutFinishInput::class)
            ->run(function (WorkoutFinishInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                // The time the user finished. #58's OccurredAt replaces the clock here once it lands, for offline replays.
                $finished = ($this->finish)($user, $input->workout_id, CarbonImmutable::now());
                $session = $finished['session'];

                return new WriteResult(
                    invalidates: [
                        ...WorkoutReads::keys($session),
                        // OFF-023: Today shows the session done; SCH-054: the days whose meals changed.
                        ...TodayReads::keys([DayPlans::day((string) $session->getRawOriginal('actual_start_at'))->format('Y-m-d'), ...$finished['dates']], ['day_adjust_show', 'day_redistribute_show']),
                        ...TodayReads::weekKeys($finished['week']),
                    ],
                    toast: $finished['toast'],
                );
            });
    }

    public function owned(User $user): array
    {
        return ['workout_id' => WorkoutSession::query()->where('user_id', $user->id)];
    }
}
