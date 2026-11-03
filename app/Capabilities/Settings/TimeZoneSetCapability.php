<?php

namespace App\Capabilities\Settings;

use App\Actions\Account\SetTimeZone;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Settings\Data\TimeZoneSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TimeZoneSetCapability implements HostCapability
{
    public function __construct(private readonly SetTimeZone $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // A new zone can move the user's "today", so every read built on it refetches.
        return HostDefinition::write('time_zone_set', affects: PlanReads::AFFECTS)
            ->description('Set the IANA time zone the user\'s days are counted in, such as Australia/Brisbane. The app sends the device\'s zone on each load, so it follows the user when they travel; until then it is UTC. Today, its week strip and its weigh-ins count days in it. Sending the zone already set changes nothing.')
            ->cli('time-zone', 'set')
            ->input(TimeZoneSetInput::class)
            ->run(function (TimeZoneSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: ($this->set)($user, $input->time_zone) ? PlanReads::keys($user) : []);
            });
    }
}
