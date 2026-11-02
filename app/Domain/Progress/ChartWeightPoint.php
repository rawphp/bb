<?php

namespace App\Domain\Progress;

use DateTimeImmutable;

final readonly class ChartWeightPoint
{
    public function __construct(
        public DateTimeImmutable $day,
        public float $kg,
    ) {}
}
