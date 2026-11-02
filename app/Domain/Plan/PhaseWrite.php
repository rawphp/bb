<?php

namespace App\Domain\Plan;

use App\Domain\Model\Phase;

/** One phase to save (SAFE-017): the stored phase with this id, changed, or a new one. */
final readonly class PhaseWrite
{
    public function __construct(
        public Phase $phase,
        public bool $exists,
    ) {}
}
