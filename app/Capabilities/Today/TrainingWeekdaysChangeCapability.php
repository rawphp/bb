<?php

namespace App\Capabilities\Today;

use App\Actions\Today\ChangeTrainingWeekdays;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Settings\SettingsReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\TrainingWeekdaysChangeInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TrainingWeekdaysChangeCapability implements HostCapability
{
    public function __construct(private readonly ChangeTrainingWeekdays $change) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it can reshape the running mesocycle, so the agent asks first.
        return HostDefinition::write('training_weekdays_change', affects: [...SettingsReads::REBUILDS, 'train_show', 'mesocycle_show', ...TodayReads::SHEETS], destructive: true)
            ->description('Change the ISO weekdays the user trains on (1 = Monday), from the next plan week; this week\'s sessions stay on their days. A weekday kept keeps its start time, a new one starts at 17:30 for 60 minutes. While a mesocycle runs, the same number of days moves its sessions to the new weekdays. Fewer days needs fewer_days: restructure lays the muscles out over the new days from next week, extend keeps all sessions so the mesocycle runs longer; without it the call is refused on fewer_days with what each choice does, to ask the user. The targets are recalculated from tomorrow and today\'s unchecked meals are placed again.')
            ->cli('training-weekdays', 'change')
            ->input(TrainingWeekdaysChangeInput::class)
            ->run(function (TrainingWeekdaysChangeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $changed = ($this->change)($user, $input);

                return new WriteResult(
                    invalidates: [
                        ...SettingsReads::keys($changed['dates']),
                        ...($changed['mesocycle_id'] === null ? [] : MesocycleReads::keys($changed['mesocycle_id'])),
                    ],
                    toast: $changed['toast'],
                );
            });
    }
}
