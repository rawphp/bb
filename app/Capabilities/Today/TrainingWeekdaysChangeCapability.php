<?php

namespace App\Capabilities\Today;

use App\Actions\Today\ChangeTrainingWeekdays;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Settings\SettingsReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\TrainingWeekdaysChangeInput;
use App\Capabilities\Workout\WorkoutReads;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TrainingWeekdaysChangeCapability implements HostCapability
{
    public function __construct(private readonly ChangeTrainingWeekdays $change) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it can reshape the running mesocycle, so the agent asks first. SCH-056, UI-010: today's re-target
        // shows a toast with Undo, which takes back the whole change.
        return HostDefinition::write('training_weekdays_change', affects: [...SettingsReads::REBUILDS, 'train_show', 'mesocycle_show', 'workout_show', ...TodayReads::SHEETS], reversible: true, destructive: true)
            ->description('Change the ISO weekdays the user trains on (1 = Monday) from the next plan week; this week\'s sessions stay on their days, a kept weekday keeps its start time and a new one starts at 17:30 for 60 minutes. While a mesocycle runs, the same number of days moves its sessions to the new weekdays, and fewer days needs fewer_days (restructure lays the muscles out over the new days, extend keeps all sessions so the mesocycle runs longer), else the call is refused on fewer_days with what each choice does, to ask the user. The targets are recalculated from tomorrow and today\'s unchecked meals are placed again.')
            ->cli('training-weekdays', 'change')
            ->input(TrainingWeekdaysChangeInput::class)
            ->run(function (TrainingWeekdaysChangeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $changed = ($this->change)($user, $input);
                $mesocycle = $changed['mesocycle_id'] === null ? null : Mesocycle::query()->find($changed['mesocycle_id']);

                return new WriteResult(
                    invalidates: array_values(array_unique([
                        ...SettingsReads::keys($changed['dates']),
                        ...($changed['today'] === null ? [] : TodayReads::forDays([$changed['today']['date']], $changed['today']['week'])),
                        // Each session's day, and with a reshape its slots and the length, change with the weekdays.
                        ...($mesocycle === null ? [] : [...MesocycleReads::keys((string) $mesocycle->id), ...WorkoutReads::everyWorkout($mesocycle)]),
                    ])),
                    toast: $changed['toast'],
                );
            });
    }
}
