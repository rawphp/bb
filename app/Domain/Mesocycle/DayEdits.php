<?php

namespace App\Domain\Mesocycle;

/**
 * What a session edit written to the mesocycle does to the day it runs (rp-clone WorkoutModel+Edit): MESO-045's "Apply
 * to all remaining weeks" on Add exercise and Reorder exercises, EX-008's rest-of-mesocycle swap, and WO-031's day
 * name. A slot is `{id, exercise_id: ?int, muscle_group, sets, carried: ?{weight_kg, reps}}`, as the mesocycle stores
 * it; each rule takes the day's slots and returns them changed.
 */
final class DayEdits
{
    /** WO-031: an exercise added from the day menu gets three sets, as in the session. */
    public const ADDED_SETS = 3;

    /**
     * MESO-045: the exercise joins the day last, with three sets and no carried load (WO-006).
     *
     * @template TSlot of array<string, mixed>
     *
     * @param  list<TSlot>  $slots
     * @return list<TSlot|array{id: string, exercise_id: int, muscle_group: string, sets: int, carried: null}>
     */
    public static function appending(array $slots, string $slotId, int $exerciseId, string $muscleGroup): array
    {
        return [...$slots, ['id' => $slotId, 'exercise_id' => $exerciseId, 'muscle_group' => $muscleGroup, 'sets' => self::ADDED_SETS, 'carried' => null]];
    }

    /**
     * MESO-045 (rp-clone mesocycleFollowing): the day's slots in the order of the session's exercises. Each slot whose
     * exercise is in the session takes the place of the session's order, by the exercise's first place there; a slot
     * whose exercise is not keeps its place, and an exercise only the session has does not join the day.
     *
     * @template TSlot of array{exercise_id: ?int}
     *
     * @param  list<TSlot>  $slots
     * @param  list<int>  $exerciseIds  the session's exercises in order
     * @return list<TSlot>
     */
    public static function following(array $slots, array $exerciseIds): array
    {
        $rank = [];
        foreach ($exerciseIds as $index => $id) {
            $rank[$id] ??= $index;
        }
        $places = array_keys(array_filter($slots, fn (array $slot) => $slot['exercise_id'] !== null && isset($rank[$slot['exercise_id']])));
        $ordered = array_map(fn (int $place) => $slots[$place], $places);
        // usort is stable, so slots of the same exercise keep their order.
        usort($ordered, fn (array $a, array $b) => $rank[$a['exercise_id']] <=> $rank[$b['exercise_id']]);
        foreach ($places as $k => $place) {
            $slots[$place] = $ordered[$k];
        }

        return $slots;
    }

    /**
     * EX-008: a slot of `$from` runs `$to` instead: slot `$slotId`, the one the session's exercise was planned from, else
     * the day's one slot of `$from`. A day that runs `$from` more than once, with no slot named, is left as it is: which
     * slot is meant is not known. The carried load was the old exercise's (MESO-061), so it goes. A slot that no longer
     * runs `$from`, as after the same swap, is left as it is, so the swap can be sent again.
     *
     * @template TSlot of array{id: string, exercise_id: ?int, carried?: mixed}
     *
     * @param  list<TSlot>  $slots
     * @return list<TSlot>
     */
    public static function swapping(array $slots, int $from, int $to, ?string $slotId = null): array
    {
        $candidates = array_keys(array_filter($slots, fn (array $slot) => $slot['exercise_id'] === $from && ($slotId === null || $slot['id'] === $slotId)));
        if (count($candidates) !== 1) {
            return $slots;
        }
        $slots[$candidates[0]]['exercise_id'] = $to;
        $slots[$candidates[0]]['carried'] = null;

        return $slots;
    }

    /** WO-031: the name a day is relabelled to, trimmed; null when nothing is left. */
    public static function label(string $label): ?string
    {
        $trimmed = trim($label);

        return $trimmed === '' ? null : $trimmed;
    }
}
