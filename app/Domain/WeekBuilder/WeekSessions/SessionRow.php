<?php

namespace App\Domain\WeekBuilder\WeekSessions;

use App\Domain\WeekBuilder\WorkoutSessionRecord;
use DateTimeImmutable;

/**
 * A session's stored row, by week and day number from 1 (SCH-050 to SCH-057): where it was moved, re-timed or
 * started, and whether it was skipped.
 */
final readonly class SessionRow
{
    /**
     * @param  ?DateTimeImmutable  $start  The row's actual start, else its planned start; null leaves the session on its
     *                                     training weekday.
     */
    public function __construct(
        public int $week,
        public int $day,
        public ?DateTimeImmutable $start,
        public bool $isSkipped,
    ) {}

    /**
     * SCH-040, SCH-050 to SCH-057: the rows of one mesocycle's sessions, placed by their actual start, else their
     * planned start. Mesocycle ids match whatever their case.
     *
     * @param  list<WorkoutSessionRecord>  $records
     * @return list<self>
     */
    public static function rows(array $records, ?string $mesocycleId): array
    {
        if ($mesocycleId === null) {
            return [];
        }

        $rows = [];
        foreach ($records as $record) {
            if ($record->day !== null && $record->mesocycleId !== null && strcasecmp($record->mesocycleId, $mesocycleId) === 0) {
                $rows[] = new self($record->week, $record->day, $record->actualStartAt ?? $record->plannedStartAt, $record->isSkipped);
            }
        }

        return $rows;
    }
}
