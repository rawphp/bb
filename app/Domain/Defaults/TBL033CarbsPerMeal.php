<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL033CarbsPerMeal\Row;

/**
 * TBL-033 Carbs per meal on training days (Renaissance Diet 2.0, Table 10.8), one workout a day. The rest share is
 * split evenly over the meals not named.
 */
final class TBL033CarbsPerMeal
{
    /** With an intra-workout shake (SCH-043) the shake takes this many points from the post-workout share. */
    public const INTRA_WORKOUT_SHAKE_TAKES_POINTS = 10.0;

    /** @return list<Row> */
    public static function rows(): array
    {
        return [
            new Row(2, 40, 60, null, null, null),
            new Row(3, 30, 45, 25, null, null),
            new Row(4, 25, 35, 25, 15, 1),
            new Row(5, 20, 30, 20, 30, 2),
            new Row(6, 18, 25, 18, 39, 3),
        ];
    }

    public static function row(int $meals): ?Row
    {
        foreach (self::rows() as $row) {
            if ($row->meals === $meals) {
                return $row;
            }
        }

        return null;
    }
}
