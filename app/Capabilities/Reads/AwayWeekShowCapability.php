<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\AwayWeekShowInput;
use App\Capabilities\Reads\Data\AwayWeekShowOutput;
use App\Capabilities\Reads\Today\AwayWeekPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\AwayOffer;
use DomainException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AwayWeekShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('away_week_show')
            ->description('Show the Away sheet for a plan week: the weeks that can be marked away (from next week to the end of the phase), whether training pauses or the week becomes the deload, whether calories stay at rest-day targets or go to maintenance, and whether the phase end date moves. The week must be one the sheet offers. It changes nothing.')
            ->cli('week', 'away-show')
            ->input(AwayWeekShowInput::class)
            ->output(AwayWeekShowOutput::class)
            ->run(function (AwayWeekShowInput $input, CapabilityContext $context): AwayWeekShowOutput {
                /** @var User $user */
                $user = $context->user();
                $plan = PlanSnapshot::load($user) ?? throw new DomainException('There is no plan yet.');

                return AwayWeekPayload::build(AwayOffer::fromSnapshot($plan), $input->week_start);
            });
    }
}
