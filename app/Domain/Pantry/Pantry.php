<?php

namespace App\Domain\Pantry;

use App\Domain\Foods\FoodSearchSource;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\Model\DietFilter;

/**
 * The Pantry, the food picker (FOOD-020 to FOOD-028): what its tabs, curated groups, filters and search show.
 * Ported from rp-clone's PantryModel, which worked these out on the phone from lists it had loaded.
 */
final class Pantry
{
    /** FOOD-021: a curated group shows five foods, and "Show 25 more" takes it to thirty. */
    public const COLLAPSED = 5;

    public const EXPANDED = 30;

    public const SHOW_MORE_TITLE = 'Show 25 more';

    public const MORE_RESULTS_TITLE = 'More results';

    /** FOOD-062. */
    public const CREATE_CUSTOM_FOOD_TITLE = "Can't find it? Create a custom food";

    /**
     * FOOD-021, FOOD-026, FOOD-027: each curated group in FOOD-003 order with the foods that pass the filters, in the
     * order given. A group with none is left out; a group filter keeps only its own group.
     *
     * @param  list<PantryFood>  $foods  the curated foods
     * @param  list<DietFilter>  $diets  the filters that are on
     * @param  list<CuratedGroup>  $expanded  the groups opened with "Show 25 more"
     * @return list<CuratedSection>
     */
    public static function curated(array $foods, array $diets = [], ?CuratedGroup $group = null, array $expanded = []): array
    {
        $sections = [];
        foreach (CuratedGroup::cases() as $candidate) {
            if ($group !== null && $group !== $candidate) {
                continue;
            }
            $inGroup = self::filter($foods, $diets, $candidate);
            if ($inGroup === []) {
                continue;
            }
            $isExpanded = in_array($candidate, $expanded, true);
            $sections[] = new CuratedSection(
                group: $candidate,
                foods: array_slice($inGroup, 0, $isExpanded ? self::EXPANDED : self::COLLAPSED),
                hasMore: ! $isExpanded && count($inGroup) > self::COLLAPSED,
            );
        }

        return $sections;
    }

    /**
     * FOOD-026, FOOD-027: the foods that pass every diet filter that is on and, with a group filter, are in that group.
     *
     * @param  list<PantryFood>  $foods
     * @param  list<DietFilter>  $diets
     * @return list<PantryFood>
     */
    public static function filter(array $foods, array $diets = [], ?CuratedGroup $group = null): array
    {
        return array_values(array_filter(
            $foods,
            fn (PantryFood $food) => self::passesDiets($food->diets, $diets) && ($group === null || $food->group() === $group),
        ));
    }

    /**
     * FOOD-027: an item passes when it matches every filter that is on.
     *
     * @param  list<DietFilter>  $has  the filters the item passes
     * @param  list<DietFilter>  $on  the filters that are on
     */
    public static function passesDiets(array $has, array $on): bool
    {
        return array_all($on, fn (DietFilter $filter) => in_array($filter, $has, true));
    }

    /**
     * FOOD-023, FOOD-025: a search's results as the local matches (the user's Recents, Favorites and Custom foods, and
     * curated foods), then "More results" from the rest of the database, each in the order found. The Restaurants and
     * Packaged filters show only More results.
     *
     * @param  list<PantryFood>  $found
     * @param  list<string>  $ownIds  the found foods that are in the user's Recents, Favorites or Custom
     * @return array{0: list<PantryFood>, 1: list<PantryFood>}
     */
    public static function split(array $found, array $ownIds, ?FoodSearchSource $source = null): array
    {
        if ($source?->kind() !== null) {
            return [[], $found];
        }

        $local = $more = [];
        foreach ($found as $food) {
            if ($food->isCurated || in_array($food->id(), $ownIds, true)) {
                $local[] = $food;
            } else {
                $more[] = $food;
            }
        }

        return [$local, $more];
    }

    /** FOOD-024. */
    public static function didYouMean(string $corrected): string
    {
        return "Did you mean {$corrected}?";
    }

    /**
     * What an empty food list says: that nothing matches the search, that the filters hid the tab's foods, or that the
     * tab has none yet. The Curated tab always has foods, so an empty one was filtered.
     */
    public static function emptyText(PantryTab $tab, string $query, bool $filtered): string
    {
        if ($query !== '') {
            return "No foods match {$query}.";
        }

        return $filtered || $tab === PantryTab::Curated
            ? "No {$tab->foodsName()} match your filters."
            : "No {$tab->foodsName()} yet.";
    }

    /** What the empty Recipes or Saved Meals filter says: "No recipes yet.", "No recipes match pie." or "No recipes match your filters." */
    public static function emptyCollectionText(string $name, string $query, bool $filtered = false): string
    {
        return match (true) {
            $query !== '' => "No {$name} match {$query}.",
            $filtered => "No {$name} match your filters.",
            default => "No {$name} yet.",
        };
    }

    /** FOOD-003: a curated group's name. */
    public static function groupTitle(CuratedGroup $group): string
    {
        return match ($group) {
            CuratedGroup::ProteinRecommended => 'Protein · Recommended',
            CuratedGroup::ProteinSparingly => 'Protein · Use sparingly',
            CuratedGroup::ProteinBeforeBed => 'Protein · Before bed',
            CuratedGroup::Veggies => 'Veggies',
            CuratedGroup::CarbsRecommended => 'Carbs · Recommended',
            CuratedGroup::CarbsSparingly => 'Carbs · Use sparingly',
            CuratedGroup::FatRecommended => 'Fat · Recommended',
            CuratedGroup::FatSparingly => 'Fat · Use sparingly',
        };
    }
}
