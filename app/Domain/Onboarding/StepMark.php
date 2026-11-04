<?php

namespace App\Domain\Onboarding;

/**
 * One mark on setup's step track (ONB-022): a step shown, its short name, whether it is done, current or to come, and
 * whether it opens from the track (a done step, as an Edit link opens it).
 */
final readonly class StepMark
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const TODO = 'todo';

    public function __construct(
        public int $step,
        public string $label,
        public string $state,
        public bool $canOpen,
    ) {}
}
