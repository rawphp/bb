<?php

namespace App\Capabilities\Reads\Nutrition;

use App\Capabilities\Nutrition\Data\BasketAmount;
use App\Capabilities\Nutrition\Data\MealFoodAddShowOutput;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Domain\MealFit\MealEdit;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Support\Today\TodaySnapshot;

/**
 * FOOD-030: the Pantry's basket as meal_food_add would add it (MEAL-002): the picks join the meal's entries, the whole
 * meal is fitted to its target and floored as it is saved ({@see MealEdit::add()}, {@see MealEdit::planned()}), and
 * the result is worded as the meal screen words it (MealPayload). Nothing is saved.
 */
final class BasketPayload
{
    /**
     * @param  list<MealFood>  $meal  the meal's entries now
     * @param  list<array{id: string, fit: ?MealFood, isServing?: bool}>  $picks  each entry sent; `fit` is null for one Add skips, as the meal already holds it
     */
    public static function build(array $meal, array $picks, MealTarget $target): MealFoodAddShowOutput
    {
        $mealFit = new MealFit;
        $edit = new MealEdit($mealFit);
        $joining = array_values(array_filter(array_map(fn (array $pick) => $pick['fit'], $picks)));
        $after = $edit->planned($edit->add($meal, $joining, $target));
        $byId = [];
        foreach ($after as $entry) {
            $byId[$entry->id] = $entry;
        }
        $totals = $mealFit->totals($after);

        return new MealFoodAddShowOutput(
            entries: array_map(fn (array $pick) => new BasketAmount(
                id: $pick['id'],
                amount_text: $pick['fit'] === null ? null : self::amountText($byId[$pick['fit']->id], $pick['isServing'] ?? false),
            ), $picks),
            bars: [
                MealPayload::bar('Cal', $totals->kcal, $target->kcal),
                MealPayload::bar('P', $totals->proteinG, $target->proteinG),
                MealPayload::bar('F', $totals->fatG, $target->fatG),
                MealPayload::bar('C', $totals->carbsG, $target->carbsG),
            ],
            targets_met: $after !== [] && $mealFit->targetsMet($totals, $target) ? new Status(MealPayload::TARGETS_MET, Tone::Good->value) : null,
            hints: $after === [] ? [] : array_map(MealPayload::hint(...), $mealFit->fit($after, $target)->shortfalls),
        );
    }

    private static function amountText(MealFood $entry, bool $isServing): string
    {
        return $isServing ? TodaySnapshot::servingsText(RecipeServing::servings($entry)) : MealPayload::gramsText($entry);
    }
}
