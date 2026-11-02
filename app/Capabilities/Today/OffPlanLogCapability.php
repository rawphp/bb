<?php

namespace App\Capabilities\Today;

use App\Actions\Today\LogOffPlan;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\OffPlanLogInput;
use App\Models\OffPlanEntry;
use App\Models\User;
use App\Support\Today\DayPlans;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OffPlanLogCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly LogOffPlan $log) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('off_plan_log', affects: ['today_show', 'day_redistribute_show'])
            ->description('Log food eaten outside the planned meals as an extra entry on a day, with its calories, protein, fat and carbs and the time it was eaten. Use it when the user ate something off-plan, then day_redistribute_show shows what is left for the later meals. Logging the same id again edits that entry, and no meal\'s targets change.')
            ->cli('off-plan', 'log')
            ->input(OffPlanLogInput::class)
            ->run(function (OffPlanLogInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $existing = $this->ownedOrNew(OffPlanEntry::class, $input->id, $user);
                // An edit can move the entry to another day, which changes that day too.
                $was = $existing === null ? [] : [DayPlans::day($existing->date)->format('Y-m-d')];
                $entry = ($this->log)($user, $existing, $input);

                return new WriteResult(
                    invalidates: TodayReads::keys([...$was, $input->date], ['day_redistribute_show']),
                    toast: ($entry->name ?? LogOffPlan::DEFAULT_NAME).' logged',
                );
            });
    }
}
