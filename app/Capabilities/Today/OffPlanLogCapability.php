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
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OffPlanLogCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly LogOffPlan $log) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('off_plan_log', affects: ['day_redistribute_show'])
            ->description('Log food eaten outside the planned meals as an extra entry on a day, with its calories, protein, fat and carbs and the time it was eaten. Use it when the user ate something off-plan; day_redistribute_show then shows what is left for the later meals. Logging the same id again edits that entry. It changes no meal\'s targets.')
            ->cli('off-plan', 'log')
            ->input(OffPlanLogInput::class)
            ->run(function (OffPlanLogInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $entry = ($this->log)($user, $this->ownedOrNew(OffPlanEntry::class, $input->id, $user), $input);

                return new WriteResult(
                    invalidates: TodayReads::keys([$input->date], ['day_redistribute_show']),
                    toast: ($entry->name ?? LogOffPlan::DEFAULT_NAME).' logged',
                );
            });
    }
}
