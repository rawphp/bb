<?php

namespace App\Domain\Macros;

use DomainException;

/** A calorie move the app will not make (NUT-021, NUT-022, NUT-024). */
final class MoveRefusal extends DomainException
{
    private function __construct(
        public readonly MoveRefusalReason $reason,
        public readonly ?int $dayIndex = null,
    ) {
        parent::__construct(match ($reason) {
            MoveRefusalReason::DayCompleted => "Day {$dayIndex} is completed.",
            MoveRefusalReason::OutsideBand => "The move puts day {$dayIndex} outside 80% to 125% of the daily average.",
            MoveRefusalReason::BreaksFloor => "The move breaks the fat or carb floor on day {$dayIndex}.",
            MoveRefusalReason::Invalid => 'The move needs positive calories between two different days of the week.',
        });
    }

    /** NUT-024. */
    public static function dayCompleted(int $dayIndex): self
    {
        return new self(MoveRefusalReason::DayCompleted, $dayIndex);
    }

    /** NUT-022. */
    public static function outsideBand(int $dayIndex): self
    {
        return new self(MoveRefusalReason::OutsideBand, $dayIndex);
    }

    /** SAFE-009, SAFE-010. */
    public static function breaksFloor(int $dayIndex): self
    {
        return new self(MoveRefusalReason::BreaksFloor, $dayIndex);
    }

    public static function invalid(): self
    {
        return new self(MoveRefusalReason::Invalid);
    }
}
