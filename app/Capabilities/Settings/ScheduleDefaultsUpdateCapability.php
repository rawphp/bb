<?php

namespace App\Capabilities\Settings;

use App\Actions\Settings\UpdateScheduleDefaults;
use App\Capabilities\HostCapability;
use App\Capabilities\Settings\Data\ScheduleDefaultsUpdateInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ScheduleDefaultsUpdateCapability implements HostCapability
{
    public function __construct(private readonly UpdateScheduleDefaults $update) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('schedule_defaults_update', affects: SettingsReads::REBUILDS)
            ->description('Save the schedule defaults, sending every field: the eating window, meals a day, training days with their start times and minutes, busy periods and the step range for each weekday; the targets are then recalculated from tomorrow. While a mesocycle covers next week, training on different weekdays is refused, and training_weekdays_change moves its sessions instead. A different review_weekday (1 = Monday to 7 = Sunday) takes effect from the next plan-week boundary, with one week shortened or lengthened to fit; before the plan starts it sets the starting review day, and null leaves it as it is.')
            ->cli('schedule', 'defaults-update')
            ->input(ScheduleDefaultsUpdateInput::class)
            ->run(function (ScheduleDefaultsUpdateInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: SettingsReads::keys(($this->update)($user, $input)));
            });
    }
}
