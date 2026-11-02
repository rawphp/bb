<?php

namespace App\Domain\Review;

use App\Domain\Model\MealTarget;
use App\Domain\WeekBuilder\BuiltWeek;

/** REV-013: a built week's average daily calories and macros; null in training-only mode. */
final class BuiltWeekSummary
{
    public static function averageKcal(BuiltWeek $week): ?float
    {
        return self::average($week, fn (MealTarget $t) => $t->kcal);
    }

    public static function averageProteinG(BuiltWeek $week): ?float
    {
        return self::average($week, fn (MealTarget $t) => $t->proteinG);
    }

    public static function averageFatG(BuiltWeek $week): ?float
    {
        return self::average($week, fn (MealTarget $t) => $t->fatG);
    }

    public static function averageCarbsG(BuiltWeek $week): ?float
    {
        return self::average($week, fn (MealTarget $t) => $t->carbsG);
    }

    /** @param  callable(MealTarget): float  $field */
    private static function average(BuiltWeek $week, callable $field): ?float
    {
        $targets = [];
        foreach ($week->days as $day) {
            if ($day->target !== null) {
                $targets[] = $day->target;
            }
        }
        if ($targets === []) {
            return null;
        }

        return array_sum(array_map($field, $targets)) / count($targets);
    }
}
