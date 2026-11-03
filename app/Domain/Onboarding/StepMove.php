<?php

namespace App\Domain\Onboarding;

/**
 * Where Next leads (ONB-005): the step to move to, and whether the goal is cleared on the way, as when the screening
 * sends a Fat loss goal back to step 1.
 */
final readonly class StepMove
{
    public function __construct(
        public int $step,
        public bool $clearsGoal = false,
    ) {}
}
