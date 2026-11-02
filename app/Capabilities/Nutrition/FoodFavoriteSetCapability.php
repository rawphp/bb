<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Foods\SetFoodFavorite;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\FoodFavoriteSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Domain\Pantry\PantryTab;
use App\Models\Food;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class FoodFavoriteSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetFoodFavorite $setFavorite) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('food_favorite_set', affects: ['food_search'])
            ->description('Favourite a food, or take it back, so it shows on the Pantry\'s Favorites tab and ranks first in the user\'s search. Use it when the user stars or unstars a food; setting the same value again changes nothing. It changes only the user\'s favourites.')
            ->cli('food', 'favorite-set')
            ->input(FoodFavoriteSetInput::class)
            ->run(function (FoodFavoriteSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->setFavorite)($user, $input->food_id, $input->favorite);

                return new WriteResult(invalidates: self::pantryKeys());
            });
    }

    public function owned(User $user): array
    {
        return ['food_id' => Food::query()->visibleTo($user)];
    }

    /**
     * Every list shows a food's star, and a search's key holds its text, so the write names the keys it can know: the
     * Pantry as it opens, and each tab.
     *
     * @return list<string>
     */
    private static function pantryKeys(): array
    {
        return [
            ReadKey::for('food_search'),
            ...array_map(fn (PantryTab $tab) => ReadKey::for('food_search', ['tab' => $tab->value]), PantryTab::cases()),
        ];
    }
}
