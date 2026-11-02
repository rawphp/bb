<?php

namespace App\Capabilities\Today;

use App\Actions\Today\DeleteOffPlan;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\OffPlanDeleteInput;
use App\Models\OffPlanEntry;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OffPlanDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteOffPlan $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: a delete needs the user's say-so before the coach makes it.
        return HostDefinition::write('off_plan_delete', affects: ['day_redistribute_show'], destructive: true)
            ->description('Delete an off-plan food entry from its day. Use it when the user logged off-plan food by mistake or twice. It changes no meal\'s targets.')
            ->cli('off-plan', 'delete')
            ->input(OffPlanDeleteInput::class)
            ->run(function (OffPlanDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $entry = ($this->delete)($user, $input->id);

                return new WriteResult(
                    invalidates: TodayReads::keys([$entry->date->format('Y-m-d')], ['day_redistribute_show']),
                    toast: ($entry->name ?? LogOffPlan::DEFAULT_NAME).' deleted',
                );
            });
    }

    public function owned(User $user): array
    {
        return ['id' => OffPlanEntry::query()->where('user_id', $user->id)];
    }
}
