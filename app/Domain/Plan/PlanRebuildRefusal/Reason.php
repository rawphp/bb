<?php

namespace App\Domain\Plan\PlanRebuildRefusal;

/** Why the targets were left as they were. */
enum Reason: string
{
    /** There is no plan week to rebuild yet. */
    case PlanNotStarted = 'planNotStarted';
    /** PLAN-002: no phase covers the week. */
    case NoPhase = 'noPhase';
    /** SAFE-011: the BMI is at or below the floor for a cut. */
    case BmiAtOrBelowFloor = 'bmiAtOrBelowFloor';
    /** SAFE-012: the carbs or fat would fall below the safe minimum. */
    case BreaksFloor = 'breaksFloor';
    /** A calorie move was refused. */
    case Unbalanced = 'unbalanced';
    /** SCH-041: the eating window cannot hold the meals. */
    case MealsDoNotFit = 'mealsDoNotFit';
}
