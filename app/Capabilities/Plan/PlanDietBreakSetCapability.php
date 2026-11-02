<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\SetDietBreak;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PlanDietBreakSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PlanDietBreakSetCapability implements HostCapability
{
    public function __construct(private readonly SetDietBreak $setDietBreak) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('plan_diet_break_set', affects: PlanReads::AFFECTS)
            ->description('Turn a Fat loss or Mini-cut deload week\'s diet break off, so that week deloads in a deficit, or back on to maintenance calories; week_start is the deload\'s start_date from plan_show. Use it only for a deload still ahead or under way. Warn the user first that deloading in a deficit is one of the biggest mistakes RP warns about.')
            ->cli('plan', 'diet-break-set')
            ->input(PlanDietBreakSetInput::class)
            ->run(function (PlanDietBreakSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->setDietBreak)($user, $input->week_start, $input->diet_break);

                return new WriteResult(
                    invalidates: PlanReads::keys($user),
                    toast: $input->diet_break ? 'Deload back at maintenance calories' : 'Deload now in a deficit',
                );
            });
    }
}
