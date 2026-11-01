<?php

namespace App\Domain\Progression;

/** What a Retarget shows. */
enum RetargetKind: string
{
    /** PROG-024: a new rep target for the same effort. */
    case Reps = 'reps';
    /** PROG-025: too far from last week; only the target RIR. */
    case RirOnly = 'rirOnly';
}
