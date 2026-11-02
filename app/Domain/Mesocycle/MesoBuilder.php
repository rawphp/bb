<?php

namespace App\Domain\Mesocycle;

use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\PriorityPreset;
use App\Domain\Mesocycle\MesoBuilder\SpecialisingInFatLoss;
use App\Domain\Mesocycle\MesoBuilder\TooManyEmphasizedInMuscleGain;
use App\Domain\Mesocycle\MesoBuilder\Warning;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use Closure;

/**
 * MESO-020 to MESO-023, MESO-013, MESO-016, MESO-046: the Meso Builder's session structure,
 * its priority warnings, "pick for me" and exercise order.
 *
 * Priorities are keyed by MuscleGroup value, as on Mesocycle.
 */
final class MesoBuilder
{
    /** MESO-023: back, quads, hamstrings and glutes recover slower and go 2x a week. */
    private const SLOWER_RECOVERING = [MuscleGroup::Back, MuscleGroup::Quads, MuscleGroup::Hamstrings, MuscleGroup::Glutes];

    /** MESO-023: delts, arms, calves and abs recover faster and go up to 3x a week. */
    private const FASTER_RECOVERING = [
        MuscleGroup::SideDelts, MuscleGroup::RearDelts, MuscleGroup::FrontDelts,
        MuscleGroup::Biceps, MuscleGroup::Triceps, MuscleGroup::Calves, MuscleGroup::Abs,
    ];

    /** MESO-021: the most Emphasize muscles the builder suggests; null when there is no limit. */
    public static function emphasizeLimit(PhaseType $phase): ?int
    {
        return $phase === PhaseType::MuscleGain ? 2 : null;
    }

    /**
     * MESO-020, TBL-012: every muscle at the phase's preset. Muscle gain starts all at Grow
     * and the user picks up to 2 to Emphasize.
     *
     * @return array<string, Priority>
     */
    public static function defaultPriorities(Column $column): array
    {
        $priority = match (TBL012TrainingDefaults::row($column)->priorityPreset) {
            PriorityPreset::AllEmphasize => Priority::Emphasize,
            PriorityPreset::AllMaintain => Priority::Maintain,
            PriorityPreset::AllGrow, PriorityPreset::UpToTwoEmphasizeRestGrowOrMaintain => Priority::Grow,
        };

        $priorities = [];
        foreach (MuscleGroup::cases() as $muscle) {
            $priorities[$muscle->value] = $priority;
        }

        return $priorities;
    }

    /**
     * MESO-021, PH-010, PH-011.
     *
     * @param  array<string, Priority>  $priorities
     * @return list<Warning>
     */
    public static function warnings(array $priorities, PhaseType $phase): array
    {
        $emphasized = count(array_filter($priorities, fn (Priority $p) => $p === Priority::Emphasize));

        $limit = self::emphasizeLimit($phase);
        if ($limit !== null && $emphasized > $limit) {
            return [new TooManyEmphasizedInMuscleGain($emphasized)];
        }

        if ($phase->isFatLoss() && $emphasized > 0) {
            $others = array_values(array_filter(
                MuscleGroup::cases(),
                fn (MuscleGroup $muscle) => isset($priorities[$muscle->value]) && $priorities[$muscle->value] !== Priority::Emphasize,
            ));
            if ($others !== []) {
                return [new SpecialisingInFatLoss($others)];
            }
        }

        return [];
    }

    /**
     * MESO-022, MESO-023: "Day 1" to "Day n", one empty slot per muscle per day it is trained.
     * Each muscle goes at the TBL-012 minimum frequency, slower-recovering muscles 2x and faster
     * ones 3x, never more than the days. Its days are spaced evenly, on the least-loaded days.
     * Slots are in priority order (MESO-046).
     *
     * @param  array<string, Priority>  $priorities
     * @param  Closure(): string  $newId  a fresh id for each session and slot
     * @return list<Session>
     */
    public static function sessions(int $daysPerWeek, array $priorities, Column $column, Closure $newId): array
    {
        $days = max(1, $daysPerWeek);
        $minimum = TBL012TrainingDefaults::row($column)->minimumFrequencyPerWeek;

        $muscles = array_values(array_filter(MuscleGroup::cases(), fn (MuscleGroup $m) => isset($priorities[$m->value])));
        usort($muscles, fn (MuscleGroup $a, MuscleGroup $b) => $priorities[$b->value]->bars() <=> $priorities[$a->value]->bars());

        /** @var list<list<MuscleGroup>> $dayMuscles */
        $dayMuscles = array_fill(0, $days, []);

        foreach ($muscles as $muscle) {
            $recovery = match (true) {
                in_array($muscle, self::SLOWER_RECOVERING, true) => 2,
                in_array($muscle, self::FASTER_RECOVERING, true) => 3,
                default => $minimum,
            };
            $frequency = min($days, max($minimum, $recovery));
            $spacing = array_map(fn (int $step) => intdiv($step * $days, $frequency), range(0, $frequency - 1));

            $best = 0;
            $bestLoad = PHP_INT_MAX;
            for ($offset = 0; $offset < $days; $offset++) {
                $load = array_sum(array_map(fn (int $step) => count($dayMuscles[($offset + $step) % $days]), $spacing));
                if ($load < $bestLoad) {
                    [$best, $bestLoad] = [$offset, $load];
                }
            }

            foreach ($spacing as $step) {
                $dayMuscles[($best + $step) % $days][] = $muscle;
            }
        }

        return array_map(fn (array $muscles, int $index) => new Session(
            id: $newId(),
            name: 'Day '.($index + 1),
            slots: array_map(fn (MuscleGroup $muscle) => new ExerciseSlot($newId(), '', $muscle, 0), $muscles),
        ), $dayMuscles, array_keys($dayMuscles));
    }

    /**
     * MESO-013, MESO-016: the highest stimulus-to-fatigue exercise for the muscle; compounds
     * first, then list order. This puts high-rated exercises first in Fat loss.
     *
     * @param  list<Exercise>  $exercises
     */
    public static function pickForMe(MuscleGroup $muscle, array $exercises): ?Exercise
    {
        $candidates = array_values(array_filter($exercises, fn (Exercise $e) => $e->muscle === $muscle));

        return self::sortedBy($candidates, fn (Exercise $e) => self::key(0, $e))[0] ?? null;
    }

    /**
     * MESO-013: fills every slot with no exercise; picked slots are kept.
     *
     * @param  list<Session>  $sessions
     * @param  list<Exercise>  $exercises
     * @return list<Session>
     */
    public static function pickForSessions(array $sessions, array $exercises): array
    {
        return array_map(fn (Session $session) => new Session(
            $session->id,
            $session->name,
            array_map(function (ExerciseSlot $slot) use ($exercises) {
                $pick = $slot->exerciseName === '' ? self::pickForMe($slot->muscle, $exercises) : null;

                return $pick === null ? $slot : new ExerciseSlot($slot->id, $pick->name, $slot->muscle, $slot->sets);
            }, $session->slots),
        ), $sessions);
    }

    /**
     * MESO-046: priority first, then stimulus-to-fatigue, compounds before isolations; list
     * order on ties. Muscles without a priority go last.
     *
     * @param  list<Exercise>  $exercises
     * @param  array<string, Priority>  $priorities
     * @return list<Exercise>
     */
    public static function order(array $exercises, array $priorities): array
    {
        return self::sortedBy($exercises, fn (Exercise $e) => self::key(self::bars($priorities, $e->muscle), $e));
    }

    /**
     * MESO-046 for a session's slots, looking exercises up by name and muscle. A slot with no
     * known exercise ranks below every rated one within its priority.
     *
     * @param  array<string, Priority>  $priorities
     * @param  list<Exercise>  $exercises
     */
    public static function orderSession(Session $session, array $priorities, array $exercises): Session
    {
        $find = function (ExerciseSlot $slot) use ($exercises): ?Exercise {
            foreach ($exercises as $exercise) {
                if ($exercise->name === $slot->exerciseName && $exercise->muscle === $slot->muscle) {
                    return $exercise;
                }
            }

            return null;
        };

        return new Session($session->id, $session->name, self::sortedBy(
            $session->slots,
            fn (ExerciseSlot $slot) => self::key(self::bars($priorities, $slot->muscle), $find($slot)),
        ));
    }

    /** @param  array<string, Priority>  $priorities */
    private static function bars(array $priorities, MuscleGroup $muscle): int
    {
        return ($priorities[$muscle->value] ?? null)?->bars() ?? 0;
    }

    /**
     * Lower sorts first: more bars, then a higher rating, then compounds. No exercise sorts
     * after every rating.
     *
     * @return array{int, int, int}
     */
    private static function key(int $bars, ?Exercise $exercise): array
    {
        if ($exercise === null) {
            return [-$bars, 1, 1];
        }

        return [-$bars, -$exercise->stimulusToFatigue->rank(), $exercise->isCompound ? 0 : 1];
    }

    /**
     * A stable sort by key: list order on ties.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  Closure(T): array{int, int, int}  $key
     * @return list<T>
     */
    private static function sortedBy(array $items, Closure $key): array
    {
        $keyed = array_map(fn ($item, int $index) => [[...$key($item), $index], $item], $items, array_keys($items));
        usort($keyed, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_column($keyed, 1);
    }
}
