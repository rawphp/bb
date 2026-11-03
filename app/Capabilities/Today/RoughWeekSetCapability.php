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
        // SCH-063: the week strip shows the flag, and WorkoutSnapshot reads it to hold back the next occurrence.
        return HostDefinition::write('rough_week_set', affects: ['today_show', 'week_show'], reversible: true)
            ->description('Mark the plan week in progress as a rough week, or unmark it. Only this plan week\'s start is accepted (as the week strip shows it, when the plan began mid-week), and a deload or an away week cannot be marked. It records the flag and shows it on the week strip. Training this week runs as planned. A session finished or re-answered while the week is marked adds no sets at its next occurrence; set removals still apply. While the week stays marked, next week\'s weights and reps do not rise over this week\'s logged sets, though recovery adjustments and a deload still apply.')
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
