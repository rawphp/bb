<?php

namespace App\Domain\Defaults\TBL030ActivityFactor;

final readonly class Row
{
    /**
     * @param  ?int  $maxSteps  null means no upper bound
     */
    public function __construct(
        public int $minSteps,
        public ?int $maxSteps,
        public float $factor,
    ) {}
}
