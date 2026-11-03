<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Energy\EnergyRules;
use App\Domain\Model\PhaseType;

/** The notes under the week strip. They only warn: nothing a user saves is refused on them (SCH-062). */
final class WeekWarnings
{
    /**
     * SCH-062, PLAN-009: "Too much cardio" when the plan week holds more hard Other activities than Fat loss (and
     * Mini-cut) allows. A week outside any phase never warns.
     *
     * @param  list<string>  $intensities  the week's Other activities' intensities
     * @return list<Status>
     */
    public static function cardio(?PhaseType $phase, array $intensities): array
    {
        if ($phase === null) {
            return [];
        }
        $hard = count(array_filter($intensities, fn (string $intensity) => $intensity === 'hard'));
        $text = EnergyRules::cardioWarning($phase, $hard);

        return $text === null ? [] : [new Status($text, Tone::Warning->value)];
    }
}
