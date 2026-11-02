<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\CustomFoodRow;
use App\Capabilities\Nutrition\Data\CustomFoodsShowInput;
use App\Capabilities\Nutrition\Data\CustomFoodsShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\Food;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class CustomFoodsShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('custom_foods_show')
            ->description('List the user\'s custom foods by name, each with its serving and calories and the values it was entered with. Use it before editing or deleting a custom food. It changes nothing.')
            ->cli('custom-food', 'list')
            ->input(CustomFoodsShowInput::class)
            ->output(CustomFoodsShowOutput::class)
            ->run(function (CustomFoodsShowInput $input, CapabilityContext $context): CustomFoodsShowOutput {
                /** @var User $user */
                $user = $context->user();
                $foods = Food::query()->where('user_id', $user->id)->orderBy('name')->orderBy('id')->get()
                    ->map(fn (Food $food): CustomFoodRow => self::row($food))
                    ->values()
                    ->all();

                return new CustomFoodsShowOutput(
                    foods: $foods,
                    empty_text: $foods === [] ? 'No custom foods yet. Create one from the Pantry.' : null,
                );
            });
    }

    private static function row(Food $food): CustomFoodRow
    {
        $serving = $food->customServing();

        return new CustomFoodRow(
            food_id: $food->id,
            name: $food->name,
            detail: $serving->detail(),
            brand: $food->brand,
            barcode: $food->barcode,
            serving_size: $serving->size,
            serving_unit: $serving->unit,
            serving_grams: $serving->grams,
            protein: $serving->protein,
            fat: $serving->fat,
            carbs: $serving->carbs,
            fiber: $serving->fiber,
        );
    }
}
