<?php

namespace App\Domain\Model;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use DateTimeImmutable;

/** A block of training weeks (the last is the deload) under one phase type. */
final readonly class Mesocycle
{
    /**
     * @param  list<Session>  $sessions  the weekly rotation; a week's own sessions are sessionsInWeek()
     * @param  array<string, Priority>  $musclePriorities  keyed by MuscleGroup value
     * @param  bool  $isResensitization  a resensitization mesocycle (PH-036); only meaningful under maintenance
     * @param  list<list<Session>>  $earlierWeeks  SCH-039: the weeks before the training weekdays last changed, as they ran
     * @param  list<list<string>>  $laterWeeks  SCH-039 "keep all sessions": from the change on, the session ids each week
     *                                          finishes; weeks past the list (the deload) run all of $sessions
     * @param  ?DateTimeImmutable  $lastDay  MESO-043: the last day an unstarted session is planned, set only for an ended mesocycle
     */
    public function __construct(
        public string $id,
        public PhaseType $phase,
        public int $weeks,
        public array $sessions,
        public array $musclePriorities,
        public bool $isResensitization = false,
        public array $earlierWeeks = [],
        public array $laterWeeks = [],
        public ?DateTimeImmutable $lastDay = null,
    ) {}

    public function trainingDefaultsColumn(): Column
    {
        return Column::for($this->phase, $this->isResensitization);
    }

    /**
     * The sessions of a week of the mesocycle (0 is its first), in order (SCH-039, SCH-040).
     *
     * @return list<Session>
     */
    public function sessionsInWeek(int $week): array
    {
        if (isset($this->earlierWeeks[$week])) {
            return $this->earlierWeeks[$week];
        }

        $ids = $this->laterWeeks[$week - count($this->earlierWeeks)] ?? null;
        if ($ids === null) {
            return $this->sessions;
        }

        $byId = [];
        foreach ($this->sessions as $session) {
            $byId[$session->id] ??= $session;
        }

        return array_values(array_filter(array_map(fn (string $id) => $byId[$id] ?? null, $ids)));
    }
}
