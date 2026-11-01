<?php

namespace App\Domain\Defaults\TBL010Phases;

final readonly class RatePreset
{
    /**
     * @param  ?float  $ratePercentPerWeek  null for "Custom"
     */
    public function __construct(
        public string $name,
        public ?float $ratePercentPerWeek,
    ) {}
}
