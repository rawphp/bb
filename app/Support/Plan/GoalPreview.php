<?php

namespace App\Support\Plan;

use App\Domain\Model\PhaseType;
use App\Domain\Phases\PhaseWarning;

/** PH-009: what changing a phase's type does, shown before the user confirms. */
final readonly class GoalPreview
{
    /**
     * @param  list<PhaseWarning>  $warnings  PH-010 to PH-012; they never block
     * @param  list<string>  $trainingLines  the training defaults the next mesocycle takes, in words
     */
    public function __construct(
        public PhaseType $type,
        /** The plan week the new type starts on: the next one, or the phase's own start if that is later. */
        public int $startWeek,
        /** Weeks the new type runs; null with no end. */
        public ?int $weeks,
        public float $ratePercentPerWeek,
        /** TBL-010 refuses the new type for the weeks left. */
        public ?string $refusal,
        public array $warnings,
        public array $trainingLines,
        /** The week (from 1) of the running mesocycle whose progression follows the new type. */
        public ?int $progressionFromWeek,
        public ?string $runningMesocycleName,
        /** The phase starts at or after the change, so it changes type itself instead of being split. */
        public bool $replacesInPlace,
    ) {}
}
