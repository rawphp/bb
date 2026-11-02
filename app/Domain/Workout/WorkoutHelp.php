<?php

namespace App\Domain\Workout;

/** Help the workout screen carries that the feedback and finish rules decide on (WO-040, PROG-043). */
final class WorkoutHelp
{
    /** WO-040: the banner while unanswered feedback blocks Finish. */
    public const FEEDBACK_REQUIRED = 'Your feedback is required';

    /** PROG-043: the deload week's help. */
    public const DELOAD = 'The deload is meant to feel easy. Push the 0 RIR week as hard as possible before it.';
}
