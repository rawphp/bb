<?php

namespace App\Domain\Phases;

use App\Domain\Model\PhaseType;

/** PH-030: the transition screen's content. */
final readonly class PhaseTransition
{
    /**
     * @param  list<NextPhaseOption>  $recommendation  ordered; the first is the default
     * @param  list<PhaseType>  $alternatives  every phase type that is not recommended
     */
    public function __construct(
        public PhaseSummary $summary,
        public array $recommendation,
        public array $alternatives,
    ) {}
}
