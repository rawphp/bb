<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\DayRedistributeShowInput;
use App\Capabilities\Reads\Data\DayRedistributeShowOutput;
use App\Capabilities\Reads\Today\DayRedistributePayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Today\DayPlans;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DayRedistributeShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('day_redistribute_show')
            ->description('Show the Redistribute picker for a day: the meals that can still take a share of what is left (not checked in, not past, not all locked), what each would hold with excluded_meal_ids left out, and any nutrient warnings. Use it before meal_redistribute. It changes nothing.')
            ->cli('day', 'redistribute-show')
            ->input(DayRedistributeShowInput::class)
            ->output(DayRedistributeShowOutput::class)
            ->run(function (DayRedistributeShowInput $input, CapabilityContext $context): DayRedistributeShowOutput {
                /** @var User $user */
                $user = $context->user();

                return DayRedistributePayload::build(
                    $input->date,
                    DayPlans::load($user, [$input->date])->plan($input->date),
                    CarbonImmutable::now(),
                    array_map(strtolower(...), $input->excluded_meal_ids),
                );
            });
    }

    public function owned(User $user): array
    {
        return ['excluded_meal_ids' => DayTargetMeal::query()->where('user_id', $user->id)];
    }
}
