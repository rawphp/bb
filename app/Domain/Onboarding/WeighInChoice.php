<?php

namespace App\Domain\Onboarding;

/** ONB-015: a weigh-in ISO weekday with its reminder time, in minutes after midnight. */
final readonly class WeighInChoice
{
    public function __construct(
        public int $weekday,
        public int $minutes,
    ) {}
}
