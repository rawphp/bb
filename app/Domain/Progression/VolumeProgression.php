<?php

namespace App\Domain\Progression;

use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\Landmark;
use App\Domain\Defaults\TBL012TrainingDefaults\LandmarkOffset;
use App\Domain\Defaults\TBL021VolumeLandmarks;
use App\Domain\Defaults\TBL022FeedbackScores;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\Workout;

/** Sets per muscle from feedback (PROG-001 to PROG-019). */
final class VolumeProgression
{
    /** PROG-017: most sets one exercise takes in a session. */
    public const MAX_SETS_PER_EXERCISE_PER_SESSION = 5;

    /** PROG-018: load cut for the next session after "A lot" of joint pain. */
    public const LOAD_CUT_PERCENT = 10;

    /**
     * PROG-010 score from TBL-022. Missing answers count 0. Positive soreness points only count with a Low pump or
     * Not enough volume. "Pushed my limits" and "Too much" add nothing.
     */
    public static function score(FeedbackAnswers $feedback): float
    {
        $total = 0.0;
        if ($feedback->soreness !== null) {
            $gated = in_array(
                $feedback->soreness,
                TBL022FeedbackScores::POSITIVE_SORENESS_COUNTING_ONLY_WITH_LOW_PUMP_OR_NOT_ENOUGH_VOLUME,
                true,
            );
            if (! $gated || $feedback->pump === Pump::Low || $feedback->volume === VolumeFeedback::NotEnough) {
                $total += TBL022FeedbackScores::sorenessPoints($feedback->soreness);
            }
        }
        if ($feedback->pump !== null) {
            $total += TBL022FeedbackScores::pumpPoints($feedback->pump);
        }
        if ($feedback->volume !== null) {
            $total += TBL022FeedbackScores::volumePoints($feedback->volume) ?? 0.0;
        }

        return $total;
    }

    /**
     * TBL-021 landmarks, then experience, then the phase (TBL-012 start volume and ceiling, PROG-002, PROG-003,
     * PROG-016). MV moves 1 set up in Fat loss and Mini-cut (PLAN-009) and 1 down in Muscle gain. Nothing goes
     * below 0.
     */
    public static function landmarks(MuscleGroup $muscle, ExperienceLevel $experience, Column $column): PhaseLandmarks
    {
        $base = TBL021VolumeLandmarks::landmarks($muscle);
        $mev = $base->mev;
        $mrv = $base->mrv;
        if ($experience === ExperienceLevel::Beginner) {
            $mev += TBL021VolumeLandmarks::BEGINNER_MEV_OFFSET;
            $mrv += TBL021VolumeLandmarks::BEGINNER_MRV_OFFSET;
        } elseif ($experience === ExperienceLevel::Advanced) {
            $mev += TBL021VolumeLandmarks::ADVANCED_MEV_OFFSET;
        }

        $mvShift = match ($column) {
            Column::FatLoss, Column::MiniCut => 1,
            Column::MuscleGain => -1,
            Column::MaintenanceResensitization, Column::MaintenanceBalanced => 0,
        };
        $mv = max(0, $base->mv + $mvShift);

        $row = TBL012TrainingDefaults::row($column);
        $at = fn (LandmarkOffset $offset) => match ($offset->landmark) {
            Landmark::Mv => $mv,
            Landmark::Mev => $mev + $offset->sets,
            Landmark::Mrv => $mrv + $offset->sets,
        };

        return new PhaseLandmarks($mv, max(0, $at($row->startVolume)), max(0, $at($row->ceiling)));
    }

    /**
     * PROG-001, PROG-016: weekly sets a muscle starts at. Maintain starts at MV, others at MEV; never below MV or
     * the TBL-021 floor.
     */
    public static function weeklyStartSets(MuscleGroup $muscle, Priority $priority, ExperienceLevel $experience, Column $column): int
    {
        $landmarks = self::landmarks($muscle, $experience, $column);
        $start = $priority === Priority::Maintain ? $landmarks->mv : $landmarks->mev;

        return max($start, $landmarks->mv, TBL021VolumeLandmarks::STARTING_VOLUME_FLOOR_SETS);
    }

    /**
     * Week 1 of a mesocycle: each prioritised muscle's weekly start split across the sessions that train it (earlier
     * sessions take the remainder, at least 2 sets each), then across that muscle's slots in the session. Muscles
     * without a priority are left unchanged.
     *
     * @return list<Session>
     */
    public static function weekOneSessions(Mesocycle $mesocycle, ExperienceLevel $experience): array
    {
        /** @var list<list<int>> $sets */
        $sets = array_map(fn (Session $session) => self::setsOf($session), $mesocycle->sessions);

        foreach ($mesocycle->musclePriorities as $key => $priority) {
            $muscle = MuscleGroup::from($key);
            $trainingIndices = array_keys(array_filter(
                $mesocycle->sessions,
                fn (Session $session) => self::slotIndices($session, $muscle) !== [],
            ));
            if ($trainingIndices === []) {
                continue;
            }
            $weekly = self::weeklyStartSets($muscle, $priority, $experience, $mesocycle->trainingDefaultsColumn());
            foreach ($trainingIndices as $position => $sessionIndex) {
                $sessionSets = max(
                    self::evenShare($weekly, count($trainingIndices), $position),
                    TBL021VolumeLandmarks::MINIMUM_SETS_PER_SESSION_FOR_DIRECTLY_TRAINED_MUSCLE,
                );
                $slotIndices = self::slotIndices($mesocycle->sessions[$sessionIndex], $muscle);
                foreach ($slotIndices as $slotPosition => $slotIndex) {
                    $sets[$sessionIndex][$slotIndex] = self::evenShare($sessionSets, count($slotIndices), $slotPosition);
                }
            }
        }

        return array_map(
            fn (Session $session, array $sessionSets) => self::withSets($session, $sessionSets),
            $mesocycle->sessions,
            $sets,
        );
    }

    /** PROG-010 to PROG-018: next week's same session from this workout's feedback. */
    public static function nextOccurrence(Workout $workout, Mesocycle $mesocycle, ExperienceLevel $experience): NextOccurrence
    {
        $session = $workout->session;
        $sets = self::setsOf($session);
        $outcomes = [];
        $column = $mesocycle->trainingDefaultsColumn();
        $row = TBL012TrainingDefaults::row($column);
        $otherSlots = [];
        foreach ($mesocycle->sessions as $other) {
            if ($other->id !== $session->id) {
                array_push($otherSlots, ...$other->slots);
            }
        }

        foreach ($workout->feedback as $key => $feedback) {
            $priority = $mesocycle->musclePriorities[$key] ?? null;
            $muscle = MuscleGroup::from($key);
            $indices = self::slotIndices($session, $muscle);
            if ($priority === null || $indices === []) {
                continue;
            }
            $pain = fn (int $index): JointPain => $feedback->jointPain[$session->slots[$index]->id] ?? JointPain::None;

            $change = 0;
            $atRecoveryLimit = false;
            $suggestsAddingExercise = false;
            $loadCutSlotIds = array_values(array_map(
                fn (int $index) => $session->slots[$index]->id,
                array_filter($indices, fn (int $index) => $pain($index) === JointPain::ALot),
            ));

            $score = self::score($feedback);
            if ($feedback->volume === VolumeFeedback::TooMuch || $score <= -1) {
                $removed = self::removalSlot($sets, $indices, $pain);
                if ($removed !== null) {
                    $sets[$removed]--;
                    $change = -1;
                }
            } elseif ($feedback->volume !== VolumeFeedback::PushedMyLimits) {
                $wanted = min(self::wantedAdds($priority, $score), $row->maxSetsAddedPerSessionPerWeek);
                if ($wanted > 0) {
                    $weekly = array_sum(array_map(fn (int $index) => $sets[$index], $indices))
                        + array_sum(array_map(fn (ExerciseSlot $slot) => $slot->muscle === $muscle ? $slot->sets : 0, $otherSlots));
                    $mrv = self::landmarks($muscle, $experience, $column)->mrv;
                    $allowed = min($wanted, max(0, $mrv - $weekly));
                    $atRecoveryLimit = $allowed < $wanted;
                    while ($change < $allowed && ($target = self::bestSlot($indices, $sets, $pain)) !== null) {
                        $sets[$target]++;
                        $change++;
                    }
                    $suggestsAddingExercise = $change < $allowed
                        && array_all($indices, fn (int $index) => $sets[$index] >= self::MAX_SETS_PER_EXERCISE_PER_SESSION);
                }
            }
            $outcomes[$key] = new MuscleOutcome(
                $change, $atRecoveryLimit, $atRecoveryLimit ? 'At your recovery limit for '.$muscle->title() : null,
                $suggestsAddingExercise, $loadCutSlotIds,
            );
        }

        return new NextOccurrence(self::withSets($session, $sets), $outcomes);
    }

    /** PROG-019: add or remove sets on one slot by hand. Never below 0 and not capped. */
    public static function adjustSets(Mesocycle $mesocycle, string $sessionId, string $slotId, int $delta): Mesocycle
    {
        $sessions = $mesocycle->sessions;
        foreach ($sessions as $sessionIndex => $session) {
            if ($session->id !== $sessionId) {
                continue;
            }
            foreach ($session->slots as $slotIndex => $slot) {
                if ($slot->id === $slotId) {
                    $sets = self::setsOf($session);
                    $sets[$slotIndex] = max(0, $slot->sets + $delta);
                    $sessions[$sessionIndex] = self::withSets($session, $sets);

                    return new Mesocycle(
                        $mesocycle->id, $mesocycle->phase, $mesocycle->weeks, $sessions, $mesocycle->musclePriorities,
                        $mesocycle->isResensitization, $mesocycle->earlierWeeks, $mesocycle->laterWeeks, $mesocycle->lastDay,
                    );
                }
            }

            return $mesocycle;
        }

        return $mesocycle;
    }

    /** PROG-010: Emphasize 1+ adds 1, 2+ adds 2; Grow 2+ adds 1; Maintain never adds. */
    private static function wantedAdds(Priority $priority, float $score): int
    {
        return match ($priority) {
            Priority::Emphasize => $score >= 2 ? 2 : ($score >= 1 ? 1 : 0),
            Priority::Grow => $score >= 2 ? 1 : 0,
            Priority::Maintain => 0,
        };
    }

    /**
     * Best joint feedback first, earliest slot on ties. Moderate or A lot of pain and slots at the 5-set cap cannot
     * take a set (PROG-017, PROG-018).
     *
     * @param  list<int>  $indices
     * @param  array<int, int>  $sets
     * @param  callable(int): JointPain  $pain
     */
    private static function bestSlot(array $indices, array $sets, callable $pain): ?int
    {
        $best = null;
        foreach ($indices as $index) {
            $rank = self::painRank($pain($index));
            if ($sets[$index] >= self::MAX_SETS_PER_EXERCISE_PER_SESSION || $rank >= 2) {
                continue;
            }
            if ($best === null || $rank < self::painRank($pain($best))) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * The slot to take 1 set from: the worst joint pain (latest on ties), keeping the muscle at 2 sets or more in
     * the session. Null when no set can go.
     *
     * @param  array<int, int>  $sets
     * @param  list<int>  $indices
     * @param  callable(int): JointPain  $pain
     */
    private static function removalSlot(array $sets, array $indices, callable $pain): ?int
    {
        $current = array_sum(array_map(fn (int $index) => $sets[$index], $indices));
        if ($current <= TBL021VolumeLandmarks::MINIMUM_SETS_PER_SESSION_FOR_DIRECTLY_TRAINED_MUSCLE) {
            return null;
        }
        $worst = null;
        foreach ($indices as $index) {
            if ($sets[$index] > 0 && ($worst === null || self::painRank($pain($index)) >= self::painRank($pain($worst)))) {
                $worst = $index;
            }
        }

        return $worst;
    }

    private static function painRank(JointPain $pain): int
    {
        return match ($pain) {
            JointPain::None => 0,
            JointPain::Low => 1,
            JointPain::Moderate => 2,
            JointPain::ALot => 3,
        };
    }

    /** An even split of $total; earlier parts take the remainder. */
    private static function evenShare(int $total, int $parts, int $position): int
    {
        return intdiv($total, $parts) + ($position < $total % $parts ? 1 : 0);
    }

    /** @return list<int> */
    private static function slotIndices(Session $session, MuscleGroup $muscle): array
    {
        return array_keys(array_filter($session->slots, fn (ExerciseSlot $slot) => $slot->muscle === $muscle));
    }

    /** @return list<int> */
    private static function setsOf(Session $session): array
    {
        return array_map(fn (ExerciseSlot $slot) => $slot->sets, $session->slots);
    }

    /** @param array<int, int> $sets indexed like the session's slots */
    private static function withSets(Session $session, array $sets): Session
    {
        return new Session($session->id, $session->name, array_map(
            fn (ExerciseSlot $slot, int $count) => new ExerciseSlot($slot->id, $slot->exerciseName, $slot->muscle, $count),
            $session->slots,
            $sets,
        ));
    }
}
