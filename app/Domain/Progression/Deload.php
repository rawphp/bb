<?php

namespace App\Domain\Progression;

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;

/** Deload week rules (PROG-041, PROG-042). */
final class Deload
{
    /** PROG-042: muscles dropped from the deload. */
    public const REMOVED_MUSCLES = [MuscleGroup::Traps, MuscleGroup::Forearms];

    /**
     * Which half of the week a session falls in. Sessions are in week order, 0-based; the middle session of an odd
     * count is in the first half.
     */
    public static function half(int $sessionIndex, int $sessionsPerWeek): DeloadHalf
    {
        return $sessionIndex < intdiv($sessionsPerWeek + 1, 2) ? DeloadHalf::First : DeloadHalf::Second;
    }

    /** PROG-041: sets halved, rounded down, minimum 1. */
    public static function sets(int $sets): int
    {
        return max(1, intdiv($sets, 2));
    }

    /** PROG-041: reps halved, rounded down. */
    public static function reps(int $reps): int
    {
        return intdiv($reps, 2);
    }

    /** PROG-041: same weight in the first half, halved in the second. No equipment-step rounding here. */
    public static function weight(float $weight, DeloadHalf $half): float
    {
        return match ($half) {
            DeloadHalf::First => $weight,
            DeloadHalf::Second => $weight / 2,
        };
    }

    /** The deload version of a session: traps and forearms removed, remaining sets halved. */
    public static function session(Session $session): Session
    {
        $slots = [];
        foreach ($session->slots as $slot) {
            if (! in_array($slot->muscle, self::REMOVED_MUSCLES, true)) {
                $slots[] = new ExerciseSlot($slot->id, $slot->exerciseName, $slot->muscle, self::sets($slot->sets));
            }
        }

        return new Session($session->id, $session->name, $slots);
    }
}
