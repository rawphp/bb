<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\PhaseType;

/** One goal on step 1 (ONB-003). `detail` says why it is disabled, when it is (ONB-005). */
final readonly class GoalOption
{
    public function __construct(
        public PhaseType $goal,
        public string $title,
        public ?string $detail,
        public bool $isEnabled,
    ) {}
}
