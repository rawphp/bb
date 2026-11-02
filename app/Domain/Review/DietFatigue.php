<?php

namespace App\Domain\Review;

use App\Domain\Defaults\TBL051ReviewThresholds;

/** REV-040: diet fatigue. */
final readonly class DietFatigue
{
    /** @param  float|null  $loggedDaysDrop  the earlier weeks' average logged days minus the newest week's; null without enough earlier weeks */
    public function __construct(
        public int $deficitWeeksSinceBreak,
        public bool $isStalled,
        public ?float $loggedDaysDrop,
    ) {}

    /** TBL-051: a long deficit, a stall, or adherence falling. */
    public function isHigh(): bool
    {
        return $this->deficitWeeksSinceBreak >= TBL051ReviewThresholds::DIET_FATIGUE_DEFICIT_WEEKS
            || $this->isStalled
            || ($this->loggedDaysDrop !== null && $this->loggedDaysDrop >= TBL051ReviewThresholds::DIET_FATIGUE_LOGGED_DAYS_DROP);
    }
}
