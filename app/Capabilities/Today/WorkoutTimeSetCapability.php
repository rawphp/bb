<?php

namespace App\Capabilities\Today;

use App\Actions\Today\SetWorkoutTime;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\WorkoutTimeSetInput;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutTimeSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetWorkoutTime $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-056, UI-010: a re-target shows a toast with Undo.
        return HostDefinition::write('workout_time_set', affects: ['today_show', 'week_show', 'train_show', 'mesocycle_show', 'workout_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Set the start time of a planned mesocycle session on its own day, from today on. Use it when the user will train earlier or later that day. The day\'s unchecked meals are placed again around the new time; a session already started, done or skipped is refused.')
            ->cli('workout', 'time-set')
            ->input(WorkoutTimeSetInput::class)
            ->run(function (WorkoutTimeSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $timed = ($this->set)($user, $input);

                return new WriteResult(
                    invalidates: $timed['dates'] === [] ? [] : [...MesocycleReads::keys($input->mesocycle_id), TodayReads::workout($input->mesocycle_id, $input->week, $input->day), ...TodayReads::forDays($timed['dates'], $timed['week'])],
                    toast: $timed['toast'],
                );
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::savedBy($user)];
    }
}
