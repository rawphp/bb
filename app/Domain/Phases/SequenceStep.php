<?php

namespace App\Domain\Phases;

use App\Domain\Model\PhaseType;

/** PH-032: one step of the Gain-first sequence. */
final readonly class SequenceStep
{
    /** @param  ?int  $mesocycles  null = open ended */
    public function __construct(
        public PhaseType $phase,
        public bool $isResensitization,
        public ?int $mesocycles,
    ) {}
}
