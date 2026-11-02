<?php

namespace App\Capabilities\Nutrition;

use App\Actions\SavedMeals\SaveSavedMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\SavedMealFood;
use App\Capabilities\Nutrition\Data\SavedMealSaveInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\SavedMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class SavedMealSaveCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly SaveSavedMeal $save) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('saved_meal_save', affects: ['saved_meals_show'])
            ->description('Save a meal\'s foods under a name as a saved meal, with their amounts, cooked state and locks, so they can be loaded into any meal later. Give meal_id to save a planned meal\'s foods (recipe servings are left out), or foods to save a list of foods and grams, such as a scanned meal, locked. A new saved meal takes a new id; sending an existing one\'s id replaces its name and foods.')
            ->cli('saved-meal', 'save')
            ->input(SavedMealSaveInput::class)
            ->run(function (SavedMealSaveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $existing = $this->ownedOrNew(SavedMeal::class, $input->id, $user);

                $saved = ($this->save)(
                    $user,
                    $existing,
                    $input->id,
                    $input->name,
                    $input->meal_id,
                    array_map(fn (SavedMealFood $food) => ['food_id' => $food->food_id, 'grams' => $food->grams], $input->foods),
                );

                return new WriteResult(invalidates: [ReadKey::for('saved_meals_show')], toast: "Saved {$saved->name}");
            });
    }

    public function owned(User $user): array
    {
        return [
            'meal_id' => DayTargetMeal::query()->where('user_id', $user->id)->whereHas('dayTarget'),
            'foods.*.food_id' => Food::query()->visibleTo($user),
        ];
    }
}
