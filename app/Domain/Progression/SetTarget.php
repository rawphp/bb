<?php

namespace App\Domain\Progression;

/** A planned set. */
final readonly class SetTarget
{
    public function __construct(
        public ?float $weight,
        public int $reps,
        public int $rir,
    ) {}
}
