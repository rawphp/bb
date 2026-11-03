<?php

namespace App\Domain\Onboarding;

/** One choice in step 6's rate list (ONB-008). The rate is signed as TBL-010; null for "Custom". */
final readonly class RateOption
{
    public function __construct(
        public string $name,
        public ?float $ratePercentPerWeek,
    ) {}
}
