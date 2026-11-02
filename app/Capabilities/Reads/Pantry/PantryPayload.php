<?php

namespace App\Capabilities\Reads\Pantry;

use App\Capabilities\Nutrition\Data\CreateCustomFood;
use App\Capabilities\Nutrition\Data\DietChip;
use App\Capabilities\Nutrition\Data\FoodSearchInput;
use App\Capabilities\Nutrition\Data\FoodSearchOutput;
use App\Capabilities\Nutrition\Data\FoodSection;
use App\Capabilities\Nutrition\Data\InMealFood;
use App\Capabilities\Nutrition\Data\RecipeRow;
use App\Capabilities\Nutrition\Data\SavedMealRow;
use App\Capabilities\Nutrition\Data\Suggestion;
use App\Domain\Foods\FoodSearchSource;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\Model\DietFilter;
use App\Domain\Model\MealTarget;
use App\Domain\Pantry\CuratedSection;
use App\Domain\Pantry\FoodUnit;
use App\Domain\Pantry\Pantry;
use App\Domain\Pantry\PantryRecipe;
use App\Domain\Pantry\PantrySavedMeal;
use App\Domain\Pantry\PantryTab;

/**
 * food_search's payload (FOOD-020 to FOOD-028, FOOD-062): the Recipes or Saved Meals filter, a search, or a tab, with
 * the diet filters and the meal's foods. Every value comes ready to show, so the Pantry screen computes nothing.
 */
final class PantryPayload
{
    public static function build(FoodSearchInput $input, PantrySnapshot $snapshot): FoodSearchOutput
    {
        $query = trim($input->query);
        $sections = $recipes = $savedMeals = [];
        $suggestion = $createCustomFood = $emptyText = null;

        if ($input->collection === 'recipes') {
            $recipes = array_map(self::recipeRow(...), array_values(array_filter(
                $snapshot->recipes,
                fn (PantryRecipe $recipe) => Pantry::passesDiets($recipe->diets, $snapshot->diets) && Pantry::nameMatches($recipe->name, $query),
            )));
            $emptyText = $recipes === [] ? Pantry::emptyCollectionText('recipes', $query) : null;
        } elseif ($input->collection === 'saved_meals') {
            $savedMeals = array_map(self::savedMealRow(...), array_values(array_filter(
                $snapshot->savedMeals,
                fn (PantrySavedMeal $meal) => Pantry::nameMatches($meal->name, $query),
            )));
            $emptyText = $savedMeals === [] ? Pantry::emptyCollectionText('saved meals', $query) : null;
        } else {
            $rows = new FoodRows(FoodUnit::from($input->unit), $snapshot->favoriteIds, array_keys($snapshot->mealFoods));
            $sections = $query === ''
                ? self::tabSections($input, $snapshot, $rows)
                : self::searchSections($input, $snapshot, $rows);
            if ($query !== '') {
                $suggestion = $snapshot->suggestion === null ? null : new Suggestion(Pantry::didYouMean($snapshot->suggestion), $snapshot->suggestion);
                $createCustomFood = new CreateCustomFood(Pantry::CREATE_CUSTOM_FOOD_TITLE, $query);
            }
            $emptyText = $sections === [] ? Pantry::emptyText(PantryTab::from($input->tab), $query, filtered: $snapshot->foods !== []) : null;
        }

        return new FoodSearchOutput(
            already_in_meal: array_map(
                fn (string $id, string $name) => new InMealFood((string) $id, $name),
                array_keys($snapshot->mealFoods),
                $snapshot->mealFoods,
            ),
            sections: $sections,
            recipes: $recipes,
            saved_meals: $savedMeals,
            suggestion: $suggestion,
            create_custom_food: $createCustomFood,
            diets: array_map(
                fn (DietFilter $filter) => new DietChip($filter->value, $filter->title(), in_array($filter, $snapshot->diets, true)),
                DietFilter::cases(),
            ),
            empty_text: $emptyText,
        );
    }

    /**
     * FOOD-020, FOOD-021: the Curated tab's groups, or the Recents, Favorites or Custom list as one section.
     *
     * @return list<FoodSection>
     */
    private static function tabSections(FoodSearchInput $input, PantrySnapshot $snapshot, FoodRows $rows): array
    {
        $group = CuratedGroup::tryFrom((string) $input->group);
        $tab = PantryTab::from($input->tab);

        if ($tab === PantryTab::Curated) {
            $expanded = array_map(CuratedGroup::from(...), $input->expanded_groups);

            return array_map(fn (CuratedSection $section) => new FoodSection(
                id: $section->group->value,
                title: Pantry::groupTitle($section->group),
                group: $section->group->value,
                show_more_title: $section->hasMore ? Pantry::SHOW_MORE_TITLE : null,
                rows: $rows->rows($section->foods),
            ), Pantry::curated($snapshot->foods, $snapshot->diets, $group, $expanded));
        }

        $foods = Pantry::filter($snapshot->foods, $snapshot->diets, $group);

        return $foods === [] ? [] : [new FoodSection(id: $tab->value, title: null, group: null, show_more_title: null, rows: $rows->rows($foods))];
    }

    /**
     * FOOD-023, FOOD-025: the local matches, then More results. The search already applied the filters.
     *
     * @return list<FoodSection>
     */
    private static function searchSections(FoodSearchInput $input, PantrySnapshot $snapshot, FoodRows $rows): array
    {
        [$local, $more] = Pantry::split($snapshot->foods, $snapshot->ownIds, FoodSearchSource::tryFrom((string) $input->source));

        return array_values(array_filter([
            $local === [] ? null : new FoodSection(id: 'matches', title: null, group: null, show_more_title: null, rows: $rows->rows($local)),
            $more === [] ? null : new FoodSection(id: 'more', title: Pantry::MORE_RESULTS_TITLE, group: null, show_more_title: null, rows: $rows->rows($more)),
        ]));
    }

    private static function recipeRow(PantryRecipe $recipe): RecipeRow
    {
        return new RecipeRow(
            recipe_id: $recipe->id,
            name: $recipe->name,
            meal_type_title: $recipe->mealType === null ? null : ucfirst($recipe->mealType),
            macros_text: self::macrosText($recipe->perServing),
            is_library: $recipe->isLibrary,
        );
    }

    /** "330 kcal · P 28 · F 3 · C 47": one serving, in whole numbers. */
    private static function macrosText(MealTarget $macros): string
    {
        $whole = fn (float $value) => number_format(round($value) + 0.0);

        return "{$whole($macros->kcal)} kcal · P {$whole($macros->proteinG)} · F {$whole($macros->fatG)} · C {$whole($macros->carbsG)}";
    }

    private static function savedMealRow(PantrySavedMeal $meal): SavedMealRow
    {
        return new SavedMealRow($meal->id, $meal->name, $meal->foodCount === 1 ? '1 food' : "{$meal->foodCount} foods");
    }
}
