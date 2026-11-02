<?php

namespace App\Support\Plan;

use App\Domain\Phases\PhaseWarning;
use DateTimeImmutable;

/** What a new length would do (PH-006, PLAN-021, PH-042). */
final readonly class LengthProposal
{
    /**
     * @param  ?array{0: PlacedMesocycle, 1: int}  $mesocycleChange  fix 2: the mesocycle and the length that puts its deload on the phase's last week
     * @param  list<PhaseWarning>  $warnings
     */
    public function __construct(
        public ?int $weeks,
        /** The first day after the phase; null with no end. */
        public ?DateTimeImmutable $end,
        /** PLAN-021: the new end falls mid-mesocycle, so the user settles it. */
        public bool $needsFix,
        /** Fix 1: the first day after the nearest deload week, when one is within 2 weeks. */
        public ?DateTimeImmutable $moveEnd,
        public ?array $mesocycleChange,
        public array $warnings,
    ) {}
}
