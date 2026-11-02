<?php

namespace App\Domain\Phases;

use App\Domain\Model\ClosedRange;

/** PH-031: how long maintenance after a cut may run, in weeks. */
final readonly class MaintenanceLength
{
    public function __construct(
        public int $defaultWeeks,
        public ClosedRange $allowedWeeks,
    ) {}
}
