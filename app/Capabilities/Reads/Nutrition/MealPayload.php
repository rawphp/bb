<?php

namespace App\Capabilities\Reads\Nutrition;

use App\Actions\Foods\MealFitFoods;
use App\Capabilities\Nutrition\Data\MacroBar;
use App\Capabilities\Nutrition\Data\Macros;
use App\Capabilities\Nutrition\Data\MealEmpty;
use App\Capabilities\Nutrition\Data\MealEntry;
use App\Capabilities\Nutrition\Data\MealHint;
use App\Capabilities\Nutrition\Data\MealShowOutput;
use App\Capabilities\Nutrition\Data\MenuItem;
use App\Capabilities\Nutrition\Data\PeriWorkout;
use App\Capabilities\Nutrition\Data\Pill;
use App\Capabilities\Reads\Today\MealMenu;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Capabilities\Reads\Today\TodayWords;
use App\Capabilities\Support\Tone;
use App\Domain\MealFit\Food as FitFood;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Shortfall;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Pantry\FoodUnit;
use App\Domain\Pantry\Pantry;
use App\Domain\Placement\MealPlacement;
use App\Domain\Progress\Adherence;
use App\Domain\Recipe\RecipeServing;
use App\Models\DayTargetMeal;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Support\Today\TodayMealItem;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The meal screen (rp-clone MealModel, MealTypes, MealModel+Menu): one meal's targets as bars, its foods and recipe
 * servings, what they cannot reach, and its tags and menu, every value decided here (CAP-001). The meal comes from
 * the day Today reads ({@see TodaySnapshot}), so the screen and the meal's Today card agree (NAV-002).
 */
final class MealPayload
{
    /** MEAL-046. */
    public const EMPTY_TITLE = 'Your meal is empty';

    /** MEAL-046. */
    public const EMPTY_NOTE = 'Because this meal has no foods in it, the app assumes you are eating this meal at target macros.';

    /** MEAL-003. */
    public const TARGETS_MET = 'Targets met';

    /** MEAL-010. */
    public const PERI_WORKOUT_HELP = "These meals carry most of the day's carbs.";

    /**
     * @throws ModelNotFoundException when the meal is not one of `$day`'s meals
     */
    public static function build(TodaySnapshot $snapshot, DateTimeImmutable $day, string $mealId): MealShowOutput
    {
        $meals = $snapshot->meals($day);
        $meal = self::find($meals, $mealId);
        $row = $snapshot->meals->first(fn (DayTargetMeal $m) => strtolower((string) $m->id) === strtolower($mealId));
        $entries = $row === null ? [] : self::entries($row);
        $fit = array_map(fn (array $entry) => $entry['fit'], $entries);
        $mealFit = new MealFit;
        $planned = $mealFit->totals($fit);

        return new MealShowOutput(
            meal_id: $meal->id,
            title: $meal->name,
            time_text: $meal->time?->shortText(),
            bars: [
                self::bar('Cal', $planned->kcal, $meal->target->kcal),
                self::bar('P', $planned->proteinG, $meal->target->proteinG),
                self::bar('F', $planned->fatG, $meal->target->fatG),
                self::bar('C', $planned->carbsG, $meal->target->carbsG),
            ],
            pills: [
                self::pill('Calories', $planned->kcal, $meal->target->kcal, ''),
                self::pill('Protein', $planned->proteinG, $meal->target->proteinG, ' g'),
            ],
            targets_met: $entries !== [] && $mealFit->targetsMet($planned, $meal->target) ? new Pill(self::TARGETS_MET, Tone::Good->value) : null,
            adjust_badge: $meal->showsAdjustBadge() ? new Pill(TodayPayload::ADJUST_BADGE, Tone::Warning->value) : null,
            peri_workout: self::periWorkout($snapshot, $day, $meals, $meal),
            check_in: NutritionPayload::checkIn($meal->checkInState()),
            hints: $entries === [] ? [] : array_map(self::hint(...), $mealFit->fit($fit, $meal->target)->shortfalls),
            entries: array_map(fn (array $entry) => $entry['row'], $entries),
            empty: $entries === [] ? new MealEmpty(self::EMPTY_TITLE, self::EMPTY_NOTE) : null,
            menu: array_map(fn (array $item) => new MenuItem($item['item'], $item['title'], $item['enabled']), MealMenu::items($meal)),
        );
    }

    /** @param  list<TodayMealItem>  $meals */
    private static function find(array $meals, string $mealId): TodayMealItem
    {
        foreach ($meals as $meal) {
            if (strtolower($meal->id) === strtolower($mealId)) {
                return $meal;
            }
        }

        throw (new ModelNotFoundException)->setModel(DayTargetMeal::class, [$mealId]);
    }

    /** MEAL-001: planned against target, in whole kcal and grams ("550/1040"), and how full to draw it. */
    private static function bar(string $label, float $planned, float $target): MacroBar
    {
        $value = round($planned);
        $goal = round($target);

        return new MacroBar(
            label: $label,
            value_text: (int) $value.'/'.(int) $goal,
            fraction: $goal > 0 ? round(min(1.0, $value / $goal), 2) : 0.0,
            tone: Tone::Neutral->value,
        );
    }

    /** MEAL-011: "on track" within 10% of the target, otherwise how far under or over, to a whole number. */
    private static function pill(string $macro, float $planned, float $target, string $unit): Pill
    {
        if (Adherence::within($planned, $target)) {
            return new Pill("{$macro} on track", Tone::Good->value);
        }
        $amount = (int) round(abs($planned - $target));

        return new Pill("{$macro} {$amount}{$unit} ".($planned < $target ? 'under' : 'over'), Tone::Warning->value);
    }

    /** MEAL-007: "Short 32 g carbs. Add a food from Carbs · Recommended." */
    private static function hint(Shortfall $shortfall): MealHint
    {
        return new MealHint(
            text: sprintf('Short %d g %s. Add a food from %s.', (int) round($shortfall->shortG), $shortfall->macro->value, Pantry::groupTitle($shortfall->group)),
            group: $shortfall->group->value,
        );
    }

    /**
     * MEAL-010: meal roles are not stored, so they come from the day's workout. The pre-workout meal is the latest one
     * before the workout starts, at most 3 hours before; the post-workout meal is the earliest one at or after it ends,
     * at most 60 minutes after. Of two meals at one minute, the first. No tag on a rest day or for a meal with no time.
     *
     * @param  list<TodayMealItem>  $meals
     */
    private static function periWorkout(TodaySnapshot $snapshot, DateTimeImmutable $day, array $meals, TodayMealItem $meal): ?PeriWorkout
    {
        $workout = $meal->time === null ? null : $snapshot->workout($day);
        if ($workout === null) {
            return null;
        }
        $start = $workout->time->minuteOfDay;
        $end = $start + $workout->minutes;
        $pre = $post = null;
        foreach ($meals as $candidate) {
            $minute = $candidate->time?->minuteOfDay;
            if ($minute === null) {
                continue;
            }
            if ($minute < $start && $start - $minute <= MealPlacement::PRE_WORKOUT_MINUTES_BEFORE_MAX && ($pre === null || $minute > $pre[1])) {
                $pre = [$candidate->id, $minute];
            }
            if ($minute >= $end && $minute - $end <= MealPlacement::POST_WORKOUT_MINUTES_AFTER_MAX && ($post === null || $minute < $post[1])) {
                $post = [$candidate->id, $minute];
            }
        }
        $detail = 'Workout '.$workout->time->shortText();

        return match ($meal->id) {
            $pre[0] ?? null => new PeriWorkout('pre', 'Pre-workout', $detail, self::PERI_WORKOUT_HELP),
            $post[0] ?? null => new PeriWorkout('post', 'Post-workout', $detail, self::PERI_WORKOUT_HELP),
            default => null,
        };
    }

    /**
     * MEAL-001, RCP-002: the planned foods and recipe servings in their shared order, each as a row and as what the fit
     * scales: the food, or the recipe serving as a food of 10 g a serving.
     *
     * @return list<array{row: MealEntry, fit: MealFood}>
     */
    private static function entries(DayTargetMeal $row): array
    {
        $entries = [];
        foreach ($row->plannedFoods as $planned) {
            $entries[] = [$planned->position, self::food($planned)];
        }
        foreach ($row->plannedRecipes as $planned) {
            $entries[] = [$planned->position, self::recipe($planned)];
        }
        usort($entries, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_map(fn (array $entry) => $entry[1], $entries);
    }

    /** @return array{row: MealEntry, fit: MealFood} */
    private static function food(PlannedFood $planned): array
    {
        $food = $planned->food;
        $fit = new MealFood(
            // Planned foods and recipe servings number their rows apart, so the fit tells them apart by kind.
            id: 'food-'.$planned->getKey(),
            // A food gone from the database counts at zero macros, as on Today's card.
            food: $food === null ? new FitFood(id: '', name: 'Food', kcal: 0, proteinG: 0, fatG: 0, carbsG: 0) : MealFitFoods::of($food),
            grams: $planned->grams,
            weighing: $planned->is_cooked ? Weighing::Cooked : Weighing::Raw,
            isLocked: $planned->is_locked,
        );
        $household = $fit->household();
        $amount = ((int) round($planned->grams)).' g';
        if ($household !== null) {
            $amount .= ' (about '.TodaySnapshot::number($household->count).' '.$household->label.($household->count == 1.0 ? '' : 's').')';
        }
        $ounces = $planned->unit === FoodUnit::Ounces->value;

        return ['fit' => $fit, 'row' => new MealEntry(
            id: (string) $planned->getKey(),
            kind: 'food',
            name: $food->name ?? 'Food',
            amount_text: $amount,
            amount: $ounces ? round($planned->grams / FoodUnit::GRAMS_PER_OUNCE, 1) : $planned->grams,
            amount_unit: $ounces ? FoodUnit::Ounces->value : FoodUnit::Grams->value,
            household_unit: $household?->label,
            weighing: $fit->weighing->value,
            macros: self::macros($fit->macros()),
            is_locked: $planned->is_locked,
        )];
    }

    /** @return array{row: MealEntry, fit: MealFood} */
    private static function recipe(PlannedRecipe $planned): array
    {
        $name = TodaySnapshot::recipeName($planned);
        $fit = RecipeServing::food('recipe-'.$planned->getKey(), $name, TodaySnapshot::perServing($planned), $planned->servings, $planned->is_locked);

        return ['fit' => $fit, 'row' => new MealEntry(
            id: (string) $planned->getKey(),
            kind: 'recipe',
            name: $name,
            amount_text: TodaySnapshot::servingsText($planned->servings),
            amount: $planned->servings,
            amount_unit: 'servings',
            household_unit: null,
            weighing: null,
            macros: self::macros($fit->macros()),
            is_locked: $planned->is_locked,
        )];
    }

    /** "355 kcal", "P 68": whole kcal and grams. */
    private static function macros(MealTarget $macros): Macros
    {
        $line = TodayWords::macros($macros);

        return new Macros(kcal: $line->kcal, protein: $line->protein, fat: $line->fat, carbs: $line->carbs);
    }
}
