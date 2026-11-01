<?php

namespace App\Domain\Defaults\TBL020MesocycleLength;

use App\Domain\Model\ClosedRange;

final readonly class Row
{
    public function __construct(
        public int $defaultWeeks,
        public ClosedRange $allowedWeeks,
    ) {}
}
