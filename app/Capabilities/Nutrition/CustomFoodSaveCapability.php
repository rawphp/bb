<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Foods\SaveCustomFood;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\CustomFoodSaveInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Domain\Foods\CustomFoodServing;
use App\Domain\Foods\FoodKind;
use App\Models\Food;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class CustomFoodSaveCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly SaveCustomFood $save) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('custom_food_save', affects: ['custom_foods_show'])
            ->description('Create a custom food, or edit one of the user\'s own, from one serving: its size and unit, its weight in grams, and its protein, fat, carbs and fiber, with an optional brand and barcode. Use it when a food is not in the database; calories are worked out from the macros. A new food takes a new id; sending an existing food\'s id replaces its values.')
            ->cli('custom-food', 'save')
            ->input(CustomFoodSaveInput::class)
            ->run(function (CustomFoodSaveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                $existing = $this->ownedOrNew(Food::class, $input->id, $user);
                // A food the user owns that is not a custom food is not this capability's to change.
                if ($existing !== null && $existing->kind !== FoodKind::Custom->value) {
                    throw (new ModelNotFoundException)->setModel(Food::class, [$input->id]);
                }

                ($this->save)(
                    $user,
                    $existing,
                    $input->id,
                    $input->name,
                    $input->brand,
                    $input->barcode,
                    new CustomFoodServing(
                        $input->serving_size, $input->serving_unit, $input->serving_grams,
                        $input->protein, $input->fat, $input->carbs, $input->fiber,
                    ),
                );

                return new WriteResult(invalidates: [ReadKey::for('custom_foods_show')]);
            });
    }
}
