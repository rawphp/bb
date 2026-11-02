<?php

namespace App\Domain\Workout;

use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use DateTimeImmutable;

/**
 * The Feedback sheets of one session (FB-001 to FB-009) and what they block (WO-040): which muscles are due, what each
 * sheet asks, whether it is answered, and whether it can still change. From rp-clone's WorkoutModel+Feedback. The
 * reads show it and the writes refuse what it does not offer, so both ask here.
 */
final readonly class FeedbackSheets
{
    /** FB-007: soreness is skipped when the muscle was last trained longer ago than this. */
    public const SORENESS_WINDOW_DAYS = 14;

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param  list<SessionExercise>  $exercises  the session's exercises in its current order
     * @param  DateTimeImmutable  $start  when the session started, or now when it has not
     * @param  list<OtherSession>  $others  the user's other sessions
     * @param  array<string, FeedbackAnswers>  $answers  the stored soreness, pump and volume, keyed by muscle
     * @param  array<int, JointPain>  $jointPain  the stored FB-002 answers, keyed by library exercise id
     */
    public function __construct(
        public array $exercises,
        public DateTimeImmutable $start,
        public bool $isDeload,
        public bool $isSkipped,
        public array $others,
        public array $answers,
        public array $jointPain,
    ) {}

    /**
     * FB-001: the muscles whose sheet is due, in the order their last exercise comes. A muscle is due once every set
     * of its last exercise that has sets is logged or skipped. FB-008: none in a deload week, nor for a skipped session.
     *
     * @return list<MuscleGroup>
     */
    public function due(): array
    {
        if ($this->isDeload || $this->isSkipped) {
            return [];
        }
        $last = [];
        foreach ($this->exercises as $index => $exercise) {
            if ($exercise->sets > 0) {
                $last[$exercise->muscle->value] = $index;
            }
        }
        asort($last);

        $due = [];
        foreach ($last as $index) {
            if ($this->exercises[$index]->isDone) {
                $due[] = $this->exercises[$index]->muscle;
            }
        }

        return $due;
    }

    public function isDue(MuscleGroup $muscle): bool
    {
        return in_array($muscle, $this->due(), true);
    }

    /**
     * FB-002: the exercises of the muscle that get a joint question, each once.
     *
     * @return list<SessionExercise>
     */
    public function asked(MuscleGroup $muscle): array
    {
        $asked = [];
        foreach ($this->exercises as $exercise) {
            if ($exercise->muscle === $muscle && $exercise->sets > 0) {
                $asked[$exercise->exerciseId] ??= $exercise;
            }
        }

        return array_values($asked);
    }

    /**
     * FB-007: soreness is asked unless the muscle has never been trained before this session, or its latest earlier
     * session started more than 14 days before this one did. Only a session done with a logged set of it counts.
     */
    public function asksSoreness(MuscleGroup $muscle): bool
    {
        $latest = null;
        foreach ($this->others as $other) {
            if ($other->isSkipped || $other->startedAt === null || $other->startedAt >= $this->start
                || ! in_array($muscle, $other->workingMuscles, true)) {
                continue;
            }
            if ($latest === null || $other->startedAt > $latest) {
                $latest = $other->startedAt;
            }
        }

        return $latest !== null
            && $this->start->getTimestamp() - $latest->getTimestamp() <= self::SORENESS_WINDOW_DAYS * self::SECONDS_PER_DAY;
    }

    /** FB-009: a session started after this one, or a later one of its mesocycle, has trained the muscle. */
    public function isLocked(MuscleGroup $muscle): bool
    {
        foreach ($this->others as $other) {
            if (! $other->isSkipped && $other->startedAt !== null
                && ($other->startedAt > $this->start || $other->isLaterInMesocycle)
                && in_array($muscle, $other->muscles, true)) {
                return true;
            }
        }

        return false;
    }

    /** Joint pain for each exercise asked, pump and volume, and soreness when it is asked. */
    public function isAnswered(MuscleGroup $muscle): bool
    {
        $answers = $this->answers[$muscle->value] ?? null;
        if ($answers === null || $answers->pump === null || $answers->volume === null) {
            return false;
        }
        if ($answers->soreness === null && $this->asksSoreness($muscle)) {
            return false;
        }

        return array_all($this->asked($muscle), fn (SessionExercise $exercise) => isset($this->jointPain[$exercise->exerciseId]));
    }

    /**
     * WO-040: the muscles whose sheet is due and not fully answered. They block Finish.
     *
     * @return list<MuscleGroup>
     */
    public function pending(): array
    {
        return array_values(array_filter($this->due(), fn (MuscleGroup $muscle) => ! $this->isAnswered($muscle)));
    }
}
