<?php

namespace App\Domain\Progression;

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use DateTimeImmutable;

/** What {@see ProgressionApply} needs: the stored rotation, the session as it ran and its feedback. */
final readonly class ProgressionInput
{
    /**
     * @param  list<array{id: string, slots: list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int}>}>  $days  the mesocycle's rotation as stored; its other days keep their counts
     * @param  int  $dayIndex  the session's day, from 0
     * @param  array<string, Priority>  $priorities  keyed by muscle; a muscle that is Off is left out
     * @param  array<string, int>  $ranSets  the sets the session ran in each slot of the day, by slot id
     * @param  array<string, FeedbackAnswers>  $muscleAnswers  soreness, pump and volume by muscle (FB-003 to FB-005)
     * @param  array<int, JointPain>  $jointPain  FB-002 answers by library exercise id
     * @param  list<MuscleGroup>  $held  PROG-050: the muscles that missed their reps two sessions running
     * @param  DateTimeImmutable  $now  when the session was done
     * @param  bool  $isRoughWeek  SCH-063: the session was logged in a rough week, so every muscle of it is held
     */
    public function __construct(
        public array $days,
        public int $dayIndex,
        public PhaseType $phase,
        public bool $isResensitization,
        public int $weeks,
        public array $priorities,
        public ExperienceLevel $experience,
        public array $ranSets,
        public array $muscleAnswers,
        public array $jointPain,
        public array $held,
        public DateTimeImmutable $now,
        public bool $isRoughWeek = false,
    ) {}
}
