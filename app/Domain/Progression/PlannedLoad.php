<?php

namespace App\Domain\Progression;

/** A set to do: weight in the user's unit and reps, either of which may be unknown. */
final readonly class PlannedLoad
{
    public function __construct(
        public ?float $weight,
        public ?int $reps,
    ) {}
}
