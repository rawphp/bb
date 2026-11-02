<?php

namespace App\Domain\Review;

use App\Domain\Defaults\TBL051ReviewThresholds;

/** REV-040: training fatigue for one week. */
final readonly class TrainingFatigue
{
    /** @param  int  $skippedSessions  counted and reported, but TBL-051 sets no threshold for it */
    public function __construct(
        public int $underperformingMuscles,
        public int $stillSoreOrTooMuchAnswers,
        public int $soreAndVolumeAnswers,
        public int $skippedSessions,
    ) {}

    /** TBL-051: enough muscles underperforming, or too large a share of "still sore" and "too much". */
    public function isHigh(): bool
    {
        if ($this->underperformingMuscles >= TBL051ReviewThresholds::TRAINING_FATIGUE_UNDERPERFORMING_MUSCLES) {
            return true;
        }
        if ($this->soreAndVolumeAnswers <= 0) {
            return false;
        }
        $share = $this->stillSoreOrTooMuchAnswers / $this->soreAndVolumeAnswers;

        return $share > TBL051ReviewThresholds::TRAINING_FATIGUE_STILL_SORE_OR_TOO_MUCH_SHARE_ABOVE;
    }
}
