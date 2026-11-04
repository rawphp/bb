<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealShowInput;
use App\Capabilities\Nutrition\Data\MealShowOutput;
use App\Capabilities\Reads\Nutrition\MealPayload;
use App\Capabilities\Reads\Nutrition\PastMeals;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('meal_show')
            ->description('Show one meal: its calorie, protein, fat and carb targets against what is planned, the planned foods and recipe servings with their amounts and macros, whether the targets are met, which macro the chosen foods cannot reach and the food group to add from, the pre- or post-workout tag, the check-in state, the meal menu with what can be used now, and on an empty meal the same meal from recent days that meal_copy can copy in. Use it to open a meal from today_show or nutrition_show before changing its foods. It changes nothing.')
            ->cli('meal', 'show')
            ->input(MealShowInput::class)
            ->output(MealShowOutput::class)
            ->run(function (MealShowInput $input, CapabilityContext $context): MealShowOutput {
                /** @var User $user */
                $user = $context->user();
                $meal = DayTargetMeal::query()->liveOf($user)->with('dayTarget')->findOrFail($input->meal_id);
                $day = TrainSnapshot::day($meal->dayTarget->date);
                $snapshot = TodaySnapshot::load($user, $day->format('Y-m-d'));
                // FOOD-005: the amounts show the first household unit.
                $snapshot->meals->first(fn (DayTargetMeal $m) => $m->is($meal))?->plannedFoods->loadMissing('food.servings');

                // MEAL-061: an empty meal offers the same meal from the days before.
                return MealPayload::build($snapshot, $day, (string) $meal->getKey(), fn () => PastMeals::before($user, $day));
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->liveOf($user)];
    }
}
