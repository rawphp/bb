<?php

namespace App\Domain\Review;

/** Why the review recommends Drop fatigue. */
enum DropFatigueReason
{
    /** REV-041. */
    case TrainingFatigue;
    /** REV-042. */
    case WeeksSinceBreak;
}
