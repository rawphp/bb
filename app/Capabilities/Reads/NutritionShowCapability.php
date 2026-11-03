<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\NutritionShowInput;
use App\Capabilities\Nutrition\Data\NutritionShowOutput;
use App\Capabilities\Reads\Nutrition\NutritionPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class NutritionShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('nutrition_show')
            ->description('Show the Nutrition tab for one day, today unless a date is given: the day\'s meals in time order, each with its time, planned against target calories and its check-in state. Use it to list the meals before opening one with meal_show. It changes nothing.')
            ->cli('nutrition', 'show')
            ->input(NutritionShowInput::class)
            ->output(NutritionShowOutput::class)
            ->run(function (NutritionShowInput $input, CapabilityContext $context): NutritionShowOutput {
                /** @var User $user */
                $user = $context->user();

                return NutritionPayload::build(TodaySnapshot::load($user, $input->date));
            });
    }
}
