<?php

namespace App\Domain\Progress;

/** What the week strip shows under a day (ANA-011). */
enum DayAdherence: string
{
    /** Before the plan start: "–" (SCH-021). */
    case BeforePlan = 'beforePlan';
    /** A finished day on target: the green dot. */
    case OnTrack = 'onTrack';
    case NoDot = 'noDot';
}
