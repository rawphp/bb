<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** One body-weight reading, stored in kg. */
final readonly class WeighIn
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $date,
        public float $weightKg,
    ) {}
}
