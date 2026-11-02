<?php

namespace App\Domain\Review;

/** The cases of RecommendationReason. */
enum RecommendationReasonKind: string
{
    /** REV-023: the calorie level that hits the target rate. */
    case TowardTargetRate = 'towardTargetRate';
    /** REV-023: the change would be under 50 kcal. */
    case ChangeTooSmall = 'changeTooSmall';
    /** REV-024. */
    case OnTarget = 'onTarget';
    /** REV-025: repeat this week. */
    case PoorAdherence = 'poorAdherence';
    /** REV-026: hold instead of cutting. */
    case WaterWeight = 'waterWeight';
    /** REV-027. */
    case Stalled = 'stalled';
    /** REV-028: more calories. */
    case GainStalled = 'gainStalled';
    /** REV-028: fewer calories. */
    case GainTooFast = 'gainTooFast';
    /** REV-029. */
    case InsideCorridor = 'insideCorridor';
    /** REV-029: steer back toward the middle. */
    case OutsideCorridor = 'outsideCorridor';
    /** REV-023 exception: back to the last non-break target. */
    case ReturnFromBreak = 'returnFromBreak';
    /** REV-032: hold. */
    case FirstWeekOfPhase = 'firstWeekOfPhase';
    /** Not enough trend to read the week's rate: hold. */
    case NoTrend = 'noTrend';
    /** REV-030: SAFE-011 or SAFE-012 refused the cut. */
    case Safety = 'safety';
}
