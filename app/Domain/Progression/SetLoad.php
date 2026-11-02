<?php

namespace App\Domain\Progression;

/** A performed set. $weight is the added load; null for pure bodyweight. */
final readonly class SetLoad
{
    public function __construct(
        public ?float $weight,
        public int $reps,
    ) {}
}
