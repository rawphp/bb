<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\DayAdjustShowInput;
use App\Capabilities\Reads\Data\DayAdjustShowOutput;
use App\Capabilities\Reads\Data\Today\AdjustDayOption;
use App\Capabilities\Reads\Data\Today\AdjustDayTotals;
use App\Capabilities\Reads\Data\Today\AdjustMealOption;
use App\Capabilities\Reads\Data\Today\AdjustProjectedMeal;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\DayBalance\DayBalance;
use App\Domain\Model\MealTarget;
use App\Support\Today\Adjustment;
use App\Support\Today\AdjustWeek;
use App\Support\Today\DayMeal;
use App\Support\Today\DayPlan;
use App\Support\Today\Redistribution;
use App\Capabilities\Reads\Today\TodayWords;
use DateTimeImmutable;
use DomainException;

/**
 * The Adjust sheet (DAY-020, DAY-021, NUT-021, SAFE-018) with the move worked out from what is on it: the meals and
 * days it can move between, both days' projected calories and protein, the meals as the move leaves them, why a move
 * is refused, and the warnings of the day (and the other day) with the move made.
 */
final class DayAdjustPayload
{
    public static function build(DayAdjustShowInput $input, AdjustWeek $week, DateTimeImmutable $today): DayAdjustShowOutput
    {
        $outcome = $week->outcome($input->mode, $input->kcal, $input->from_meal_id, $input->to_meal_id, $input->other_date, $input->direction, $today);
        $day = $week->selected ?? throw new DomainException(AdjustWeek::NO_DAY);
        $selected = $outcome->changed[0] ?? null;
        $other = $outcome->changed[1] ?? null;

        $warnings = $selected === null ? [] : Redistribution::warnings($day, $selected['meals']);
        if ($other !== null) {
            $otherDay = $week->plans->plan($other['date']->format('Y-m-d'));
            foreach (Redistribution::warnings($otherDay, $other['meals']) as $text) {
                $warnings[] = TodayWords::weekday($other['date']).": {$text}";
            }
        }

        return new DayAdjustShowOutput(
            date: $input->date,
            mode: $input->mode,
            note: DayBalance::SMALL_MISSES_NOTE,
            meal_options: array_map(
                fn (DayMeal $meal) => new AdjustMealOption($meal->id, $meal->name, $meal->time?->shortText()),
                $day->openMeals(),
            ),
            day_options: array_map(
                fn (DayPlan $option) => new AdjustDayOption($option->date->format('Y-m-d'), TodayWords::shortDate($option->date), ! $option->isFinished($today)),
                Adjustment::otherDays($day, $week->week),
            ),
            day: self::totals($day->date, $selected['target'] ?? $day->target),
            other_day: $other === null ? null : self::totals($other['date'], $other['target']),
            projected_meals: $selected === null ? [] : array_map(fn (DayMeal $meal) => new AdjustProjectedMeal(
                id: $meal->id,
                name: $meal->name,
                kcal_text: TodayWords::kcal($selected['meals'][$meal->id]->kcal),
                protein_text: TodayWords::protein($selected['meals'][$meal->id]->proteinG),
            ), $day->meals),
            refusal: $outcome->refusal,
            warnings: array_map(fn (string $text) => new Status($text, Tone::Warning->value), $warnings),
            can_save: $outcome->isPlan(),
        );
    }

    private static function totals(DateTimeImmutable $date, MealTarget $target): AdjustDayTotals
    {
        return new AdjustDayTotals(TodayWords::shortDate($date), TodayWords::kcal($target->kcal), TodayWords::protein($target->proteinG));
    }
}
