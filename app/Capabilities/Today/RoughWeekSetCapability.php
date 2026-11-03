<?php

namespace App\Capabilities\Today;

use App\Actions\Today\SetRoughWeek;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\RoughWeekSetInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RoughWeekSetCapability implements HostCapability
{
    public function __construct(private readonly SetRoughWeek $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-063: the flag is stored and shown on the week strip; nothing else reads it yet.
        return HostDefinition::write('rough_week_set', affects: ['today_show', 'week_show'], reversible: true)
            ->description('Mark the plan week in progress as a rough week, or unmark it. Only this plan week\'s start is accepted (as the week strip shows it, when the plan began mid-week), and a deload or an away week cannot be marked. It records the flag and shows it on the week strip; it changes no targets and no training.')
            ->cli('week', 'rough-set')
            ->input(RoughWeekSetInput::class)
            ->run(function (RoughWeekSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $saved = ($this->set)($user, $input);

                return new WriteResult(
                    invalidates: [...TodayReads::keys($saved['dates']), ...TodayReads::weekKeys($saved['dates'])],
                    toast: $saved['toast'],
                );
            });
    }
}
