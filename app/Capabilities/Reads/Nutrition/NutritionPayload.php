<?php

namespace App\Capabilities\Reads\Nutrition;

use App\Capabilities\Nutrition\Data\MealCheckIn;
use App\Capabilities\Nutrition\Data\NutritionMeal;
use App\Capabilities\Nutrition\Data\NutritionShowOutput;
use App\Capabilities\Reads\Data\Today\Bar;
use App\Capabilities\Reads\Today\TodayWords;
use App\Capabilities\Support\Tone;
use App\Domain\Model\MealCheckInState;
use App\Domain\Placement\MealOrder;
use App\Support\Today\TodayMealItem;
use App\Support\Today\TodaySnapshot;

/**
 * The Nutrition tab (NAV-001, rp-clone NutritionView): a day's meals, each opening the meal screen (NAV-002), with
 * its time, planned against target calories, its check-in and four macro bars (NAV-005). The day's meals are the ones
 * Today shows.
 */
final class NutritionPayload
{
    public static function build(TodaySnapshot $snapshot): NutritionShowOutput
    {
        $day = $snapshot->date;
        $isToday = $day == $snapshot->today();
        // ONB-031: as on Today, no meals before the plan starts.
        $inPlan = ! $snapshot->isBeforePlan($day) && $snapshot->train->profile !== null;
        $meals = $inPlan ? $snapshot->meals($day) : [];
        $order = array_keys($meals);
        usort($order, fn (int $a, int $b) => [MealOrder::minute($meals[$a]->time), $a] <=> [MealOrder::minute($meals[$b]->time), $b]);

        return new NutritionShowOutput(
            date_text: $isToday ? 'Today' : TodayWords::title($day),
            meals: array_map(fn (int $i) => self::meal($meals[$i]), $order),
            empty_text: $meals !== [] ? null : ($isToday ? 'No meals planned today.' : 'No meals planned on '.TodayWords::title($day).'.'),
        );
    }

    private static function meal(TodayMealItem $meal): NutritionMeal
    {
        $state = $meal->checkInState();
        $planned = $meal->planned();
        // NAV-005: once checked in, the bars show what was eaten; until then, the planned food.
        $food = $meal->eaten ?? $planned;

        return new NutritionMeal(
            meal_id: $meal->id,
            name: $meal->name,
            time_text: $meal->time?->shortText(),
            kcal_text: (int) round($planned->kcal).' / '.(int) round($meal->target->kcal).' kcal',
            check_in: self::checkIn($state),
            bars: [
                Bar::of('Cal', $food->kcal, $meal->target->kcal),
                Bar::of('P', $food->proteinG, $meal->target->proteinG),
                Bar::of('F', $food->fatG, $meal->target->fatG),
                Bar::of('C', $food->carbsG, $meal->target->carbsG),
            ],
        );
    }

    /** MEAL-047: the check-in icon's text and tone, as the meal screen shows it. */
    public static function checkIn(MealCheckInState $state): MealCheckIn
    {
        return new MealCheckIn(
            state: $state->value,
            text: $state->label(),
            tone: match ($state) {
                MealCheckInState::NotCheckedIn => Tone::Neutral->value,
                MealCheckInState::OnTrack => Tone::Good->value,
                MealCheckInState::Under, MealCheckInState::Over => Tone::Warning->value,
            },
        );
    }
}
