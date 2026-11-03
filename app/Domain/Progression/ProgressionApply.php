<?php

namespace App\Domain\Progression;

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;
use App\Domain\Model\Workout;

/**
 * PROG-010 to PROG-019, PROG-050, PROG-051, SCH-063, UI-010: a finished session and its feedback, turned into the day's new slot
 * counts. From rp-clone's ProgressionApply.
 *
 * The base of each slot is the sets the session actually ran in it, not the count stored, so sets added or deleted in
 * the session carry forward (PROG-019) and applying again with the same answers gives the same counts. A slot the
 * session did not run keeps its count.
 */
final class ProgressionApply
{
    public static function apply(ProgressionInput $input): ProgressionResult
    {
        $day = $input->days[$input->dayIndex] ?? null;
        if ($day === null) {
            return new ProgressionResult($input->dayIndex, [], [], [], []);
        }
        $slots = $day['slots'];
        // PROG-019: a slot's base is the sets the session ran in it. A slot it did not run keeps its stored count and
        // takes no part, so working the day out again never moves it.
        $ran = [];
        foreach ($slots as $position => $slot) {
            $sets = $input->ranSets[$slot['id']] ?? 0;
            if ($sets > 0) {
                $ran[$position] = $sets;
            }
        }

        // The domain tells slots apart by id; a slot's place makes it unique whatever its stored id looks like. The
        // day's slots that did not run sit in a session of their own: VolumeProgression reads the other sessions only
        // for the muscle's weekly total, so they still count toward the ceiling (PROG-015) and nothing moves them.
        $sessions = [];
        $unrun = [];
        $positionOf = [];
        foreach ($input->days as $dayIndex => $storedDay) {
            $daySlots = [];
            foreach ($storedDay['slots'] as $position => $slot) {
                $slotSets = $dayIndex === $input->dayIndex ? ($ran[$position] ?? null) : (int) $slot['sets'];
                $exerciseSlot = new ExerciseSlot("{$dayIndex}-{$position}", '', MuscleGroup::from($slot['muscle_group']), $slotSets ?? (int) $slot['sets']);
                if ($dayIndex === $input->dayIndex) {
                    $positionOf[$exerciseSlot->id] = $position;
                }
                if ($slotSets === null) {
                    $unrun[] = $exerciseSlot;
                } else {
                    $daySlots[] = $exerciseSlot;
                }
            }
            $sessions[] = new Session("day-{$dayIndex}", '', $daySlots);
        }
        $session = $sessions[$input->dayIndex];
        $mesocycle = new Mesocycle('', $input->phase, $input->weeks, [...$sessions, new Session('unrun', '', $unrun)], $input->priorities, $input->isResensitization);

        $feedback = [];
        foreach ($input->muscleAnswers as $muscle => $answers) {
            $pain = [];
            foreach (array_keys($ran) as $position) {
                $exercise = $slots[$position]['exercise_id'] ?? null;
                if ($slots[$position]['muscle_group'] === $muscle && $exercise !== null && isset($input->jointPain[$exercise])) {
                    $pain["{$input->dayIndex}-{$position}"] = $input->jointPain[$exercise];
                }
            }
            $feedback[$muscle] = new FeedbackAnswers($pain, $answers->soreness, $answers->pump, $answers->volume);
        }
        $next = VolumeProgression::nextOccurrence(
            new Workout('', $input->now, $session, $feedback), $mesocycle, $input->experience,
        );

        $counts = array_map(fn (array $slot) => (int) $slot['sets'], $slots);
        foreach ($next->session->slots as $slot) {
            $counts[$positionOf[$slot->id]] = $slot->sets;
        }
        $outcomes = $next->outcomes;
        // PROG-050, PROG-051: a held muscle gains no sets. Its slots go back to the base, and the messages of the add
        // that did not happen go too. A removal stands. SCH-063: in a rough week every muscle of the session is held.
        $held = $input->isRoughWeek
            ? array_map(fn (string $muscle) => MuscleGroup::from($muscle), array_unique(array_column($slots, 'muscle_group')))
            : $input->held;
        foreach ($held as $muscle) {
            $outcome = $outcomes[$muscle->value] ?? null;
            if ($outcome === null || $outcome->change < 0) {
                continue;
            }
            foreach ($ran as $position => $sets) {
                if ($slots[$position]['muscle_group'] === $muscle->value) {
                    $counts[$position] = $sets;
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

    /** "+1 set", "−2 sets" (a true minus sign). */
    private static function setsText(int $change): string
    {
        $count = abs($change);

        return ($change > 0 ? '+' : '−').$count.' '.($count === 1 ? 'set' : 'sets');
    }
}
