<?php

namespace App\Domain\Defaults\TBL010Phases;

use App\Domain\Model\ClosedRange;

final readonly class Row
{
    /**
     * @param  list<RatePreset>  $presets
     * @param  ?int  $maxWeeks  null means no end
     * @param  ?ClosedRange  $recommendedWeeks  null where the spec gives no fixed range (maintenance, mini-cut)
     */
    public function __construct(
        public ClosedRange $rateRangePercentPerWeek,
        public array $presets,
        public int $minWeeks,
        public ?int $maxWeeks,
        public ?ClosedRange $recommendedWeeks,
    ) {}
}
