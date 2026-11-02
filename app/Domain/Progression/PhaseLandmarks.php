<?php

namespace App\Domain\Progression;

/** MV, MEV and MRV for one muscle after experience and phase adjustments, in sets per week. */
final readonly class PhaseLandmarks
{
    public function __construct(
        public int $mv,
        public int $mev,
        public int $mrv,
    ) {}
}
