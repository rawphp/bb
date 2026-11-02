<?php

namespace App\Domain\Plan\PhaseRefusal;

/** Why a phase length, rate or start was refused. */
enum Reason: string
{
    /** PH-005: only Maintenance may have no end. */
    case EndDateRequired = 'endDateRequired';
    /** TBL-010. */
    case TooShort = 'tooShort';
    /** TBL-010, SAFE-006, SAFE-007. */
    case TooLong = 'tooLong';
    /** TBL-010, PH-004. */
    case RateOutOfRange = 'rateOutOfRange';
    /** PLAN-008: phases start on a week boundary. */
    case NotOnWeekBoundary = 'notOnWeekBoundary';
    /** PLAN-006: nothing can follow a phase with no end. */
    case PreviousPhaseHasNoEnd = 'previousPhaseHasNoEnd';
}
