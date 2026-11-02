<?php

namespace App\Domain\Progression;

use App\Domain\Model\VolumeFeedback;

/** PROG-055: cutting volume instead of taking a break when training fatigue is high, then rebuilding it. */
final class VolumeCut
{
    /** "Too much" answered this many times running cuts by half instead of one third. */
    public const TOO_MUCH_RUNNING_FOR_HALF = 2;

    /**
     * A muscle's sets after the cut: one third fewer, or half after "Too much" twice running, the removed sets
     * rounded to the nearest set, keeping at least 1.
     *
     * @param  list<VolumeFeedback>  $volumeAnswers  the muscle's volume answers, oldest first
     */
    public static function cutSets(int $sets, array $volumeAnswers): int
    {
        if ($sets <= 0) {
            return 0;
        }
        $latest = array_slice($volumeAnswers, -self::TOO_MUCH_RUNNING_FOR_HALF);
        $half = count($latest) === self::TOO_MUCH_RUNNING_FOR_HALF
            && array_all($latest, fn (VolumeFeedback $answer) => $answer === VolumeFeedback::TooMuch);
        $removed = (int) round($sets * ($half ? 1 / 2 : 1 / 3));

        return max(1, $sets - $removed);
    }

    /**
     * Next week's sets while rebuilding: one more, until feedback finds the new ceiling. Null once the last answer
     * was "Pushed my limits" or "Too much": the normal set rules take over.
     */
    public static function rebuiltSets(int $sets, ?VolumeFeedback $lastVolumeAnswer): ?int
    {
        return match ($lastVolumeAnswer) {
            VolumeFeedback::PushedMyLimits, VolumeFeedback::TooMuch => null,
            VolumeFeedback::NotEnough, VolumeFeedback::JustRight, null => $sets + 1,
        };
    }
}
