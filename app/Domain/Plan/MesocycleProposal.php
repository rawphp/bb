<?php

namespace App\Domain\Plan;

/** Mesocycle lengths that fill a phase (PLAN-020). */
final readonly class MesocycleProposal
{
    /**
     * @param  list<int>  $lengths  longest first
     * @param  bool  $endsOnDeload  true when the phase's last week is the last mesocycle's deload
     */
    public function __construct(
        public array $lengths,
        public bool $endsOnDeload,
    ) {}
}
