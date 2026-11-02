<?php

namespace App\Support\Workout;

/**
 * OFF-021: the ids a workout shows before its session row exists, so the outbox can queue workout_start and then a
 * logged set by the ids the screen showed. Each is made from what it names, so every read gives the same one, and
 * workout_start writes its rows under them.
 *
 * They are lowercase and ULID-shaped (26 Crockford base32 characters, the first 0 to 7), so Laravel's `ulid` rule
 * takes them like any client-made ULID (CAP-019).
 */
final class WorkoutIds
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /** The session of a mesocycle's week and day (both from 1), whatever the case of the mesocycle's id. */
    public static function workout(string $mesocycleId, int $week, int $day): string
    {
        return self::ulid('workout|'.strtolower($mesocycleId)."|{$week}|{$day}");
    }

    /** A planned exercise of the workout: the day's slot it comes from. */
    public static function block(string $workoutId, string $slotId): string
    {
        return self::ulid("block|{$workoutId}|{$slotId}");
    }

    /** A planned set of the workout: its slot and its place in the slot's sets (from 0). */
    public static function set(string $workoutId, string $slotId, int $index): string
    {
        return self::ulid("set|{$workoutId}|{$slotId}|{$index}");
    }

    /** 128 bits of a sha256: 3 for the first character, then 5 for each of the other 25. */
    private static function ulid(string $seed): string
    {
        $bits = '';
        foreach (str_split(substr(hash('sha256', $seed, true), 0, 16)) as $byte) {
            $bits .= sprintf('%08b', ord($byte));
        }

        $id = self::ALPHABET[bindec(substr($bits, 0, 3))];
        for ($offset = 3; $offset < 128; $offset += 5) {
            $id .= self::ALPHABET[bindec(substr($bits, $offset, 5))];
        }

        return $id;
    }
}
