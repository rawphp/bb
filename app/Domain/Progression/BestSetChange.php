<?php

namespace App\Domain\Progression;

/**
 * WO-011: how a session's best set moved on last week's: the weight it gained or lost, else, at the same weight, the
 * reps.
 */
final readonly class BestSetChange
{
    public function __construct(
        public float $weight,
        public int $reps,
    ) {}

    public function isUp(): bool
    {
        return $this->weight > 0 || ($this->weight == 0 && $this->reps > 0);
    }
}
