<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\ExperienceLevel;

/** One experience option on step 7 (ONB-010), with the first mesocycle's default length (TBL-020). */
final readonly class ExperienceOption
{
    public function __construct(
        public ExperienceLevel $level,
        public string $title,
        public string $detail,
        public int $mesocycleWeeks,
    ) {}
}
