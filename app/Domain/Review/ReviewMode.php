<?php

namespace App\Domain\Review;

/** Which steps a weekly review has (rp-clone WeeklyReviewModel's ReviewMode). */
enum ReviewMode: string
{
    /** REV-003: the seven steps. */
    case Full = 'full';
    /** REV-005, SAFE-003: weigh-ins, training and progress. */
    case TrainingOnly = 'trainingOnly';
    /** SCH-061: the weigh-ins and next week's preview. */
    case Away = 'away';
}
