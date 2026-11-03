<?php

namespace App\Capabilities\WeighIns;

use App\Actions\WeighIns\SetWeighInSchedule;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\WeighIns\Data\WeighInScheduleEntryInput;
use App\Capabilities\WeighIns\Data\WeighInScheduleSetInput;
use App\Models\User;
use App\Support\UserDay;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeighInScheduleSetCapability implements HostCapability
{
    public function __construct(private readonly SetWeighInSchedule $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: entries left out are deleted (WT-007's Delete schedule), so the coach needs the user's say-so.
        return HostDefinition::write('weigh_in_schedule_set', affects: WeighInReads::AFFECTS, destructive: true)
            ->description('Replace the user\'s whole weigh-in schedule: entries of weekdays, each with a reminder time, at least 2 different days a week and each day in one entry only. To delete an entry, send the others. The time only sets the reminder; the user can weigh in at any time.')
            ->cli('weigh-in', 'schedule-set')
            ->input(WeighInScheduleSetInput::class)
            ->run(function (WeighInScheduleSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->set)($user, array_map(
                    fn (WeighInScheduleEntryInput $entry) => ['weekdays' => $entry->weekdays, 'time' => $entry->time],
                    $input->entries,
                ));

                // Today shows the weigh-in on each scheduled weekday, so each day of the week ahead can change; the client
                // keeps today, tomorrow and the rest of the week warm (OFF-004). The week is the user's (SET-012).
                $today = UserDay::today($user);

                return new WriteResult(invalidates: WeighInReads::keys(array_map(
                    fn (int $offset) => $today->modify("+{$offset} days")->format('Y-m-d'),
                    range(0, 6),
                )));
            });
    }
}
