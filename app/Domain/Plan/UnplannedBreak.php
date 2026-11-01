<?php

namespace App\Domain\Plan;

/** A break nobody planned. Each pushes a phase's end date out by a week (PLAN-025). */
enum UnplannedBreak: string
{
    /** Drop fatigue (REV-011). */
    case DropFatigue = 'dropFatigue';
    /** A recovery diet break (PROG-052). */
    case RecoveryDietBreak = 'recoveryDietBreak';
    /** An away week (SCH-060). */
    case Away = 'away';
}
