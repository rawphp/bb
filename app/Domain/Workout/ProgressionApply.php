<?php

namespace App\Domain\Workout;

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;
use App\Domain\Model\Workout;
use App\Domain\Progression\VolumeProgression;

/**
 * PROG-010 to PROG-019, PROG-050, PROG-051, UI-010: a finished session and its feedback, turned into the day's new slot
 * counts. From rp-clone's ProgressionApply.
 *
 * The base of each slot is the sets the session actually ran, not the count stored, so sets added or deleted in the
 * session carry forward (PROG-019) and applying again with the same answers gives the same counts.
 */
final class ProgressionApply
{
    public static function apply(ProgressionInput $input): ProgressionResult
    {
        $day = $input->days[$input->dayIndex] ?? null;
        if ($day === null) {
            return new ProgressionResult($input->dayIndex, [], [], [], []);
        }
        $slots = array_values($day['slots']);
        $base = self::baseCounts($slots, $input->setCounts);

        // The domain tells slots apart by id; a slot's place makes it unique whatever its stored id looks like.
        $sessions = [];
        foreach (array_values($input->days) as $dayIndex => $storedDay) {
            $sessions[] = new Session("day-{$dayIndex}", '', array_map(
                fn (array $slot, int $position) => new ExerciseSlot(
                    "{$dayIndex}-{$position}", '', MuscleGroup::from($slot['muscle_group']),
                    $dayIndex === $input->dayIndex ? $base[$position] : (int) $slot['sets'],
                ),
                array_values($storedDay['slots']),
                array_keys(array_values($storedDay['slots'])),
            ));
        }
        $session = $sessions[$input->dayIndex];
        $mesocycle = new Mesocycle('', $input->phase, $input->weeks, $sessions, $input->priorities, $input->isResensitization);

        $feedback = [];
        foreach ($input->muscleAnswers as $muscle => $answers) {
            $pain = [];
            foreach ($slots as $position => $slot) {
                $exercise = $slot['exercise_id'] ?? null;
                if ($slot['muscle_group'] === $muscle && $exercise !== null && isset($input->jointPain[$exercise])) {
                    $pain[$session->slots[$position]->id] = $input->jointPain[$exercise];
                }
            }
            $feedback[$muscle] = new FeedbackAnswers($pain, $answers->soreness, $answers->pump, $answers->volume);
        }
        $next = VolumeProgression::nextOccurrence(
            new Workout('', $input->now, $session, $feedback), $mesocycle, $input->experience,
        );

        $counts = array_map(fn (ExerciseSlot $slot) => $slot->sets, $next->session->slots);
        $outcomes = $next->outcomes;
        // PROG-050, PROG-051: a held muscle gains no sets. Its slots go back to the base, and the messages of the add
        // that did not happen go too. A removal stands.
        foreach ($input->held as $muscle) {
            $outcome = $outcomes[$muscle->value] ?? null;
            if ($outcome === null || $outcome->change < 0) {
                continue;
            }
            foreach ($slots as $position => $slot) {
                if ($slot['muscle_group'] === $muscle->value) {
                    $counts[$position] = $base[$position];
                }
            }
            unset($outcomes[$muscle->value]);
        }

        $slotSets = [];
        $previous = [];
        $changes = [];
        foreach ($slots as $position => $slot) {
            $slotSets[$slot['id']] = $counts[$position];
            $previous[$slot['id']] = (int) $slot['sets'];
            $changes[$slot['muscle_group']] = ($changes[$slot['muscle_group']] ?? 0) + $counts[$position] - (int) $slot['sets'];
        }
        $order = array_keys($changes);
        $changes = array_filter($changes, fn (int $change) => $change !== 0);

        $lines = [];
        if ($slotSets !== $previous) {
            foreach ($changes as $muscle => $change) {
                $lines[] = MuscleGroup::from($muscle)->title().' '.self::setsText($change).' next week';
            }
            foreach ($order as $muscle) {
                if (($outcomes[$muscle] ?? null)?->recoveryMessage !== null) {
                    $lines[] = $outcomes[$muscle]->recoveryMessage;
                }
            }
            foreach ($order as $muscle) {
                if (($outcomes[$muscle] ?? null)?->suggestsAddingExercise === true) {
                    $lines[] = 'Add an exercise for '.MuscleGroup::from($muscle)->title().' to keep growing its sets';
                }
            }
        }

        return new ProgressionResult($input->dayIndex, $slotSets, $previous, $changes, $lines);
    }

    /**
     * PROG-019: each slot's base. The first slot of an exercise takes the sets the session ran of it; a slot whose
     * exercise has none, or whose exercise an earlier slot took, keeps its stored count.
     *
     * @param  list<array{exercise_id: ?int, sets: int}>  $slots
     * @param  array<int, int>  $setCounts
     * @return list<int>
     */
    private static function baseCounts(array $slots, array $setCounts): array
    {
        $taken = [];

        return array_map(function (array $slot) use ($setCounts, &$taken): int {
            $exercise = $slot['exercise_id'] ?? null;
            $ran = $exercise === null ? 0 : ($setCounts[$exercise] ?? 0);
            if ($exercise === null || isset($taken[$exercise]) || $ran <= 0) {
                return (int) $slot['sets'];
            }
            $taken[$exercise] = true;

            return $ran;
        }, $slots);
    }

    /** "+1 set", "−2 sets" (a true minus sign). */
    private static function setsText(int $change): string
    {
        $count = abs($change);

        return ($change > 0 ? '+' : '−').$count.' '.($count === 1 ? 'set' : 'sets');
    }
}
