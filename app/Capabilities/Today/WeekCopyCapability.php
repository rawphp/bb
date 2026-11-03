<?php

namespace App\Capabilities\Today;

use App\Actions\Today\CopyWeek;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\WeekCopyInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeekCopyCapability implements HostCapability
{
    public function __construct(private readonly CopyWeek $copy) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: a copy shows a toast with Undo.
        return HostDefinition::write('week_copy', affects: CopyReads::AFFECTS, reversible: true)
            ->description('Copy every planned food and recipe serving of the plan week that week_start falls in to the same weekdays of the next week, as day_copy pairs meals. The copies re-fit to their meals\' targets, keep the meal times and arrive unlocked; checked-in meals and days without targets are skipped.')
            ->cli('week', 'copy')
            ->input(WeekCopyInput::class)
            ->run(function (WeekCopyInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $written = ($this->copy)($user, $input->week_start);

                return new WriteResult(
                    invalidates: CopyReads::keys($user, $written),
                    toast: $written === [] ? CopyReads::toast(0, 'week') : 'Copied to next week',
                );
            });
    }
}
