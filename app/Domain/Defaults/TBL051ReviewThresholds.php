<?php

namespace App\Domain\Defaults;

/** TBL-051 Review weights and fatigue thresholds. */
final class TBL051ReviewThresholds
{
    /** REV-021: last 3 eligible weeks, newest first. */
    public const EXPENDITURE_WEEK_WEIGHTS_NEWEST_FIRST = [0.5, 0.3, 0.2];

    /** REV-041: this many muscles flagged underperforming in the week, or more. */
    public const TRAINING_FATIGUE_UNDERPERFORMING_MUSCLES = 2;

    /** REV-041: more than this share of the week's soreness and volume answers are "I'm still sore!" or "Too much". */
    public const TRAINING_FATIGUE_STILL_SORE_OR_TOO_MUCH_SHARE_ABOVE = 1.0 / 3.0;

    /** REV-040: this many deficit weeks since the last diet break, or more. */
    public const DIET_FATIGUE_DEFICIT_WEEKS = 6;

    /** REV-040: logged days down by this many or more... */
    public const DIET_FATIGUE_LOGGED_DAYS_DROP = 2;

    /** ...against the average of this many weeks. */
    public const DIET_FATIGUE_LOGGED_DAYS_AVERAGE_WEEKS = 3;

    /** PROG-053: logged reps outside the range on most sets for this many sessions running. */
    public const OUT_OF_REP_RANGE_SESSIONS_RUNNING = 2;
}
