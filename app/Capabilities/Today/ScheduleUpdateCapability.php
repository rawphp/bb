<?php

namespace App\Capabilities\Today;

use App\Actions\Today\UpdateSchedule;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Settings\SettingsReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\ScheduleUpdateInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ScheduleUpdateCapability implements HostCapability
{
    public function __construct(private readonly UpdateSchedule $update) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-056, UI-010: this day's re-target shows a toast with Undo.
        return HostDefinition::write('schedule_update', affects: [...SettingsReads::REBUILDS, 'train_show', 'mesocycle_show', 'workout_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Edit a day\'s eating window, meals a day (2 to 6), busy periods and, on a training day, the session\'s start ("HH:MM", 24-hour). apply_to this_day places that day\'s unchecked meals again from today on and stores no default; all_future_days saves the window and meal count for every day and the busy periods and training time for that weekday, then recalculates the targets from tomorrow. Checked-in and locked meals never change.')
            ->cli('schedule', 'update')
            ->input(ScheduleUpdateInput::class)
            ->run(function (ScheduleUpdateInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $updated = ($this->update)($user, $input);
                if ($updated['week'] === null) {
                    return new WriteResult(invalidates: SettingsReads::keys($updated['dates']));
                }
                $session = $updated['session'];

                return new WriteResult(
                    invalidates: [
                        ...TodayReads::forDays($updated['dates'], $updated['week']),
                        ...($session === null ? [] : [...MesocycleReads::keys($session['mesocycle_id']), TodayReads::workout($session['mesocycle_id'], $session['week'], $session['day'])]),
                    ],
                    toast: $updated['toast'],
                );
            });
    }
}
