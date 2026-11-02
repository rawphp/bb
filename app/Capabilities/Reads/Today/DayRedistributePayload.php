<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\DayRedistributeShowOutput;
use App\Capabilities\Reads\Data\Today\RedistributeRow;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\DayBalance\DayBalance;
use App\Support\Today\DayMeal;
use App\Support\Today\DayPlan;
use App\Support\Today\Redistribution;
use DateTimeImmutable;

/**
 * The Redistribute picker (DAY-002, DAY-003, DAY-021, SAFE-018): the meals that can take a share, each with the
 * calories and protein it would hold, and the warnings of the day as the picker would leave it. A day with no stored
 * target lists nothing.
 */
final class DayRedistributePayload
{
    /** @param  list<string>  $excluded */
    public static function build(string $date, ?DayPlan $day, DateTimeImmutable $now, array $excluded): DayRedistributeShowOutput
    {
        if ($day === null) {
            return new DayRedistributeShowOutput($date, DayBalance::SMALL_MISSES_NOTE, [], [], false);
        }
        $chosen = Redistribution::chosen($day, $now, $excluded);
        $projected = Redistribution::targets($day, $chosen);

        $rows = array_map(function (DayMeal $meal) use ($projected) {
            $target = $projected[$meal->id] ?? $meal->target;

            return new RedistributeRow(
                id: $meal->id,
                name: $meal->name,
                time_text: $meal->time?->shortText(),
                selected: isset($projected[$meal->id]),
                kcal_text: TodayWords::kcal($target->kcal),
                protein_text: TodayWords::protein($target->proteinG),
            );
        }, Redistribution::receivable($day, $now));

        return new DayRedistributeShowOutput(
            date: $date,
            note: DayBalance::SMALL_MISSES_NOTE,
            rows: $rows,
            warnings: array_map(fn (string $text) => new Status($text, Tone::Warning->value), Redistribution::warnings($day, $projected)),
            can_confirm: $chosen !== [],
        );
    }
}
