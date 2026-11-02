<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\FoodSearchInput;
use App\Capabilities\Nutrition\Data\FoodSearchOutput;
use App\Capabilities\Reads\Pantry\PantryPayload;
use App\Capabilities\Reads\Pantry\PantryReader;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class FoodSearchCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly PantryReader $reader) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('food_search')
            ->description('Search the food database, or list a Pantry tab (curated groups, recents, favorites, custom foods), the user\'s recipes or saved meals, under the user\'s diet filters. Use it to find a food\'s id before adding it to a meal or favouriting it; give meal_id to mark the foods already in that meal. It changes nothing.')
            ->cli('food', 'search')
            ->input(FoodSearchInput::class)
            ->output(FoodSearchOutput::class)
            ->run(function (FoodSearchInput $input, CapabilityContext $context): FoodSearchOutput {
                /** @var User $user */
                $user = $context->user();

                return PantryPayload::build($input, $this->reader->read($user, $input));
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->where('user_id', $user->id)];
    }
}
