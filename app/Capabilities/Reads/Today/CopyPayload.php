<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\CopyShowOutput;
use App\Capabilities\Reads\Data\Today\CopyEntry;
use App\Capabilities\Reads\Data\Today\CopyMeal;
use App\Capabilities\Reads\Data\Today\CopyOptionDefaults;
use App\Capabilities\Reads\Data\Today\CopySource;
use App\Capabilities\Reads\Data\Today\CopyStripDay;
use App\Capabilities\Reads\Data\Today\CopyWorkout;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Nutrition\MealPayload;
use App\Capabilities\Support\Tone;
use App\Domain\Copy\CopyOptions;
use App\Domain\MealFit\MealFit;
use App\Domain\Placement\MealOrder;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Support\Today\TodayMealItem;
use App\Support\Today\TodayPlannedEntry;
use App\Support\Today\TodaySnapshot;
use App\Support\Today\TodayWorkoutItem;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The Copy sheet (MEAL-060, SCH-034), read through {@see TodaySnapshot}: what is copied, the week strip around the
 * shown day, and, when copying a meal's foods, the shown day's meals in time order with its workout between them.
 * Copy day ticks days on the strip, so it lists no meals. A day that cannot take the copy is not enabled: one before
 * the plan or without a target, and in Copy day the source day itself. So is a meal: the source and a checked-in one.
 */
final class CopyPayload
{
    public const TARGETS_NOT_MET = 'Targets not met';

    public const NO_FOODS = 'No foods yet';

    public const COPYING_FROM_HERE = 'Copying from here';

    public const CHECKED_IN = 'Checked in';

    /**
     * @param  TodaySnapshot  $source  read around `$sourceDay`
     * @param  TodaySnapshot  $shown  read around `$shownDay`; the same snapshot when both days are in one week
     * @param  ?string  $mealId  the meal whose foods are copied; null copies the whole day
     *
     * @throws ModelNotFoundException when the source day has no target, or the meal is not one of its meals
     */
    public static function build(TodaySnapshot $source, DateTimeImmutable $sourceDay, TodaySnapshot $shown, DateTimeImmutable $shownDay, ?string $mealId): CopyShowOutput
    {
        if ($source->dayTarget($sourceDay) === null) {
            throw (new ModelNotFoundException)->setModel(DayTarget::class, [$sourceDay->format('Y-m-d')]);
        }
        $meals = $source->meals($sourceDay);
        $meal = $mealId === null ? null : self::find($meals, $mealId);
        $options = new CopyOptions;
        $timeline = $meal === null ? [] : self::timeline($shown, $shownDay);

        return new CopyShowOutput(
            mode: $meal === null ? 'day' : 'foods',
            title: $meal === null ? 'Copy day' : 'Copy foods',
            source: $meal === null ? self::daySource($sourceDay, $meals) : self::mealSource($meal),
            shown_date: $shownDay->format('Y-m-d'),
            strip: self::strip($shown, $shownDay, $meal === null ? $sourceDay : null),
            previous_week: $shown->weekShifted($shownDay, -7)?->format('Y-m-d'),
            next_week: $shown->weekShifted($shownDay, 7)?->format('Y-m-d'),
            timeline: array_map(fn (TodayMealItem|TodayWorkoutItem $item) => $item instanceof TodayMealItem
                ? new CopyEntry('meal', $item->id) : new CopyEntry('workout', 'workout'), $timeline),
            meals: array_values(array_map(
                fn (TodayMealItem $item) => self::meal($item, $meal),
                array_filter($timeline, fn ($item) => $item instanceof TodayMealItem),
            )),
            workouts: array_values(array_map(
                fn (TodayWorkoutItem $item) => new CopyWorkout('workout', $item->session->name, $item->time->shortText().' · '.$item->minutes.' min'),
                array_filter($timeline, fn ($item) => $item instanceof TodayWorkoutItem),
            )),
            options: new CopyOptionDefaults($options->updateTargetMacros, $options->updateMealTimes, $options->copyLockedState),
        );
    }

    /** @param  list<TodayMealItem>  $meals */
    private static function find(array $meals, string $mealId): TodayMealItem
    {
        foreach ($meals as $meal) {
            if (strcasecmp($meal->id, $mealId) === 0) {
                return $meal;
            }
        }

        throw (new ModelNotFoundException)->setModel(DayTargetMeal::class, [$mealId]);
    }

    /** MEAL-060, MEAL-003: the meal copied from, with its foods and whether they meet its targets. */
    private static function mealSource(TodayMealItem $meal): CopySource
    {
        $met = $meal->hasEntries() && (new MealFit)->targetsMet($meal->planned(), $meal->target);

        return new CopySource(
            name: $meal->name,
            time_text: $meal->time?->shortText(),
            foods_text: self::foods($meal),
            status: $met ? new Status(MealPayload::TARGETS_MET, Tone::Good->value) : new Status(self::TARGETS_NOT_MET, Tone::Warning->value),
        );
    }

    /**
     * SCH-034: the day copied from, with how many meals and foods it has.
     *
     * @param  list<TodayMealItem>  $meals
     */
    private static function daySource(DateTimeImmutable $day, array $meals): CopySource
    {
        $entries = array_sum(array_map(fn (TodayMealItem $meal) => count($meal->entries()), $meals));

        return new CopySource(
            name: TodayWords::title($day),
            time_text: null,
            foods_text: TodayWords::count(count($meals), 'meal').' · '.TodayWords::count($entries, 'food'),
            status: null,
        );
    }

    /**
     * SCH-020: the 7 days of the week strip around the shown day.
     *
     * @param  ?DateTimeImmutable  $sourceDay  Copy day's source, which cannot take its own copy
     * @return list<CopyStripDay>
     */
    private static function strip(TodaySnapshot $snapshot, DateTimeImmutable $shownDay, ?DateTimeImmutable $sourceDay): array
    {
        $first = $snapshot->weekStart($shownDay);

        return array_map(function (int $offset) use ($snapshot, $first, $shownDay, $sourceDay): CopyStripDay {
            $day = $first->modify("+{$offset} days");
            $beforePlan = $snapshot->isBeforePlan($day);
            $target = $beforePlan ? null : $snapshot->dayTarget($day);
            $workout = $beforePlan ? null : $snapshot->workout($day);

            return new CopyStripDay(
                date: $day->format('Y-m-d'),
                letter: $day->format('D')[0],
                number: $day->format('j'),
                calories_text: $target === null ? '–' : (string) (int) round($target->kcal),
                is_today: $day == $snapshot->today(),
                is_shown: $day == $shownDay,
                has_training: $workout !== null && $workout->state !== TodayWorkoutItem::SKIPPED,
                is_before_plan: $beforePlan,
                enabled: $target !== null && $day != $sourceDay,
            );
        }, range(0, 6));
    }

    /**
     * MEAL-060: the shown day's meals in time order with its workout between them; a meal and the workout at one
     * minute put the meal first, and meals at one minute keep their stored order.
     *
     * @return list<TodayMealItem|TodayWorkoutItem>
     */
    private static function timeline(TodaySnapshot $snapshot, DateTimeImmutable $day): array
    {
        $items = [];
        foreach ($snapshot->meals($day) as $index => $meal) {
            $items[] = [MealOrder::minute($meal->time), 0, $index, $meal];
        }
        $workout = $snapshot->isBeforePlan($day) ? null : $snapshot->workout($day);
        if ($workout !== null && $workout->state !== TodayWorkoutItem::SKIPPED) {
            $items[] = [$workout->time->minuteOfDay, 1, 0, $workout];
        }
        usort($items, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $item) => $item[3], $items);
    }

    /** MEAL-060: a destination meal, with its targets and foods, and why it cannot take the copy. */
    private static function meal(TodayMealItem $meal, ?TodayMealItem $source): CopyMeal
    {
        $note = match (true) {
            $source !== null && strcasecmp($meal->id, $source->id) === 0 => self::COPYING_FROM_HERE,
            $meal->isChecked() => self::CHECKED_IN,
            default => null,
        };
        $macros = TodayWords::macros($meal->target);

        return new CopyMeal(
            id: $meal->id,
            name: $meal->name,
            time_text: $meal->time?->shortText(),
            target_text: "{$macros->kcal} · {$macros->protein} {$macros->fat} {$macros->carbs}",
            foods_text: self::foods($meal),
            enabled: $note === null,
            note: $note,
        );
    }

    /** "Oats 80 g · Whey 30 g", or "No foods yet". */
    private static function foods(TodayMealItem $meal): string
    {
        return $meal->hasEntries()
            ? implode(' · ', array_map(fn (TodayPlannedEntry $entry) => "{$entry->name} {$entry->amount}", $meal->entries()))
            : self::NO_FOODS;
    }
}
