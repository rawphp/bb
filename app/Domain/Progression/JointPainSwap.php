<?php

namespace App\Domain\Progression;

use App\Domain\Model\JointPain;

/** EX-009: an exercise that hurts twice running should be swapped. */
final class JointPainSwap
{
    /** EX-009: this many answers running. */
    public const ANSWERS_RUNNING = 2;

    /**
     * True when the latest two joint-pain answers (FB-002), oldest first, are both Moderate or A lot.
     *
     * @param  list<JointPain>  $answers
     */
    public static function isSuggested(array $answers): bool
    {
        $latest = array_slice($answers, -self::ANSWERS_RUNNING);

        return count($latest) === self::ANSWERS_RUNNING
            && array_all($latest, fn (JointPain $pain) => $pain === JointPain::Moderate || $pain === JointPain::ALot);
    }

    public static function suggestion(string $exerciseName): string
    {
        return $exerciseName.' hurt '.self::ANSWERS_RUNNING.' sessions running. Consider swapping it.';
    }
}
