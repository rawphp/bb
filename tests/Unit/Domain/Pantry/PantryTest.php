<?php

use App\Domain\Foods\FoodSearchSource;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\MealFit\Food;
use App\Domain\Model\DietFilter;
use App\Domain\Pantry\CuratedSection;
use App\Domain\Pantry\Pantry;
use App\Domain\Pantry\PantryFood;
use App\Domain\Pantry\PantryTab;

// The rules of rp-clone's PantryModel (FOOD-020 to FOOD-028), as plain values.

/** @param  list<DietFilter>  $diets */
function pantryFood(string $id, string $name, ?CuratedGroup $group = null, array $diets = [], bool $curated = false): PantryFood
{
    return new PantryFood(
        food: new Food(id: $id, name: $name, kcal: 100, proteinG: 10, fatG: 2, carbsG: 12, curatedGroup: $group),
        isCurated: $curated || $group !== null,
        diets: $diets,
    );
}

/**
 * @param  array<string, int>  $counts  group value => how many foods
 * @return list<PantryFood>
 */
function curatedFoods(array $counts): array
{
    $foods = [];
    foreach (CuratedGroup::cases() as $group) {
        for ($number = 1; $number <= ($counts[$group->value] ?? 0); $number++) {
            $foods[] = pantryFood("{$group->value}-{$number}", "{$group->value} {$number}", $group);
        }
    }

    return $foods;
}

/**
 * @param  list<CuratedSection>  $sections
 * @return array<string, int>
 */
function sectionCounts(array $sections): array
{
    $counts = [];
    foreach ($sections as $section) {
        $counts[$section->group->value] = count($section->foods);
    }

    return $counts;
}

/**
 * @param  list<PantryFood>  $foods
 * @return list<string>
 */
function pantryNames(array $foods): array
{
    return array_map(fn (PantryFood $food) => $food->food->name, $foods);
}

it('FOOD-020 the tabs are Curated, Recents, Favorites and Custom, in that order', function () {
    expect(array_column(PantryTab::cases(), 'value'))->toBe(['curated', 'recents', 'favorites', 'custom']);
});

it('FOOD-021 shows each curated group in FOOD-003 order with five foods and Show 25 more', function () {
    $sections = Pantry::curated(curatedFoods(array_fill_keys(array_column(CuratedGroup::cases(), 'value'), 12)));

    expect(array_map(fn (CuratedSection $s) => $s->group, $sections))->toBe(CuratedGroup::cases())
        ->and(array_map(fn (CuratedSection $s) => Pantry::groupTitle($s->group), $sections))->toBe([
            'Protein · Recommended', 'Protein · Use sparingly', 'Protein · Before bed', 'Veggies',
            'Carbs · Recommended', 'Carbs · Use sparingly', 'Fat · Recommended', 'Fat · Use sparingly',
        ])
        ->and(array_values(sectionCounts($sections)))->toBe(array_fill(0, 8, 5))
        ->and(array_column($sections, 'hasMore'))->toBe(array_fill(0, 8, true))
        ->and(Pantry::SHOW_MORE_TITLE)->toBe('Show 25 more');
});

it('FOOD-021 Show 25 more opens a group to thirty and offers no more', function () {
    $sections = Pantry::curated(curatedFoods(['veggies' => 50, 'fat_recommended' => 12]), expanded: [CuratedGroup::Veggies]);

    expect(sectionCounts($sections))->toBe(['veggies' => 30, 'fat_recommended' => 5])
        ->and(array_column($sections, 'hasMore'))->toBe([false, true]);
});

it('FOOD-021 offers Show 25 more only when the group has more than five', function () {
    $sections = Pantry::curated(curatedFoods(['protein_recommended' => 6, 'veggies' => 5]));

    expect(array_column($sections, 'hasMore'))->toBe([true, false]);
});

it('FOOD-021 leaves out a group with no foods, and keeps each group\'s own order', function () {
    $sections = Pantry::curated([
        pantryFood('2', 'Spinach', CuratedGroup::Veggies),
        pantryFood('1', 'Broccoli', CuratedGroup::Veggies),
    ]);

    expect(array_map(fn (CuratedSection $s) => $s->group, $sections))->toBe([CuratedGroup::Veggies])
        ->and(pantryNames($sections[0]->foods))->toBe(['Spinach', 'Broccoli']);
});

it('FOOD-026 a group filter narrows the Curated tab to that group', function () {
    $foods = curatedFoods(['veggies' => 7, 'carbs_recommended' => 7]);

    expect(sectionCounts(Pantry::curated($foods, group: CuratedGroup::CarbsRecommended)))->toBe(['carbs_recommended' => 5])
        ->and(Pantry::curated($foods))->toHaveCount(2);
});

it('FOOD-026 a group filter narrows a list to curated foods of that group', function () {
    $foods = [
        pantryFood('1', 'Rice', CuratedGroup::CarbsRecommended),
        pantryFood('2', 'Rice cakes', CuratedGroup::CarbsSparingly),
        pantryFood('3', 'Rice pudding'),
    ];

    expect(pantryNames(Pantry::filter($foods, group: CuratedGroup::CarbsSparingly)))->toBe(['Rice cakes'])
        ->and(pantryNames(Pantry::filter($foods)))->toBe(['Rice', 'Rice cakes', 'Rice pudding']);
});

it('FOOD-027 diet filters hide the foods that do not match, in lists and curated groups', function () {
    $foods = [
        pantryFood('1', 'Chicken breast', CuratedGroup::ProteinRecommended, [DietFilter::DairyFree, DietFilter::GlutenFree, DietFilter::Paleo]),
        pantryFood('2', 'Tofu', CuratedGroup::ProteinRecommended, [DietFilter::DairyFree, DietFilter::GlutenFree, DietFilter::Vegan, DietFilter::Vegetarian]),
        pantryFood('3', 'Yogurt', CuratedGroup::ProteinRecommended, [DietFilter::GlutenFree, DietFilter::Vegetarian]),
        pantryFood('4', 'Bread'),
    ];

    expect(pantryNames(Pantry::filter($foods, [DietFilter::DairyFree])))->toBe(['Chicken breast', 'Tofu'])
        ->and(pantryNames(Pantry::curated($foods, [DietFilter::Vegetarian])[0]->foods))->toBe(['Tofu', 'Yogurt']);
});

it('FOOD-027 with several diet filters on, a food must match all of them', function () {
    $foods = [
        pantryFood('1', 'Chicken breast', diets: [DietFilter::DairyFree, DietFilter::Paleo]),
        pantryFood('2', 'Tofu', diets: [DietFilter::DairyFree, DietFilter::Vegan]),
        pantryFood('3', 'Coconut yogurt', diets: [DietFilter::Vegan]),
    ];

    expect(pantryNames(Pantry::filter($foods, [DietFilter::DairyFree, DietFilter::Vegan])))->toBe(['Tofu'])
        ->and(Pantry::passesDiets([DietFilter::Vegan], []))->toBeTrue()
        ->and(Pantry::passesDiets([], [DietFilter::Vegan]))->toBeFalse();
});

it('FOOD-023 search shows the user\'s own and curated matches first, then the rest as More results', function () {
    $found = [
        pantryFood('soup', 'Chicken noodle soup'),
        pantryFood('breast', 'Chicken breast', CuratedGroup::ProteinRecommended),
        pantryFood('stock', 'Chicken stock'),
        pantryFood('nuggets', 'Chicken nuggets'),
    ];

    [$local, $more] = Pantry::split($found, ownIds: ['soup']);

    expect(pantryNames($local))->toBe(['Chicken noodle soup', 'Chicken breast'])
        ->and(pantryNames($more))->toBe(['Chicken stock', 'Chicken nuggets'])
        ->and(Pantry::MORE_RESULTS_TITLE)->toBe('More results');
});

it('FOOD-025 the Restaurants and Packaged filters show only More results, the others only local matches', function () {
    $found = [pantryFood('mine', 'Chicken burger'), pantryFood('theirs', 'Chicken wrap')];

    foreach ([FoodSearchSource::Restaurants, FoodSearchSource::Packaged] as $source) {
        [$local, $more] = Pantry::split($found, ['mine'], $source);
        expect($local)->toBe([])->and(pantryNames($more))->toBe(['Chicken burger', 'Chicken wrap']);
    }
    foreach ([FoodSearchSource::Recents, FoodSearchSource::Favorites, FoodSearchSource::Custom] as $source) {
        [$local, $more] = Pantry::split([$found[0]], ['mine'], $source);
        expect(pantryNames($local))->toBe(['Chicken burger'])->and($more)->toBe([]);
    }
});

it('FOOD-025 the Curated filter shows curated matches as local ones', function () {
    [$local, $more] = Pantry::split([pantryFood('1', 'Chicken thigh', CuratedGroup::ProteinRecommended)], [], FoodSearchSource::Curated);

    expect(pantryNames($local))->toBe(['Chicken thigh'])->and($more)->toBe([]);
});

it('FOOD-020 FOOD-023 a recipe or saved meal matches a search by name, ignoring case', function () {
    expect(Pantry::nameMatches('Overnight oats', ''))->toBeTrue()
        ->and(Pantry::nameMatches('Overnight oats', 'OATS'))->toBeTrue()
        ->and(Pantry::nameMatches('Overnight oats', 'rice'))->toBeFalse();
});

it('FOOD-024 the suggestion asks about the corrected spelling', function () {
    expect(Pantry::didYouMean('chicken breast'))->toBe('Did you mean chicken breast?');
});

it('FOOD-020 FOOD-023 an empty Pantry says why: nothing yet, nothing past the filters, or nothing matching the search', function () {
    expect(Pantry::emptyText(PantryTab::Curated, query: '', filtered: true))->toBe('No curated foods match your filters.')
        ->and(Pantry::emptyText(PantryTab::Recents, query: '', filtered: false))->toBe('No recent foods yet.')
        ->and(Pantry::emptyText(PantryTab::Recents, query: '', filtered: true))->toBe('No recent foods match your filters.')
        ->and(Pantry::emptyText(PantryTab::Favorites, query: '', filtered: false))->toBe('No favorite foods yet.')
        ->and(Pantry::emptyText(PantryTab::Custom, query: '', filtered: false))->toBe('No custom foods yet.')
        ->and(Pantry::emptyText(PantryTab::Custom, query: 'chiken brest', filtered: false))->toBe('No foods match chiken brest.');
});
