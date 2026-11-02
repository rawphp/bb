<?php

namespace App\Domain\Retarget;

use App\Domain\Model\MuscleGroup;
use App\Domain\Retarget\WeekdayChoice\Kind;

/** SCH-039: one of the two things a change in the number of training days can do to the mesocycle. */
final readonly class WeekdayChoice
{
    /**
     * @param  list<MuscleGroup>  $underTrained  Restructure only: the muscles MESO-023's frequency cannot be met for.
     * @param  ?int  $weeks  Keep all sessions only: the weeks the mesocycle then runs.
     */
    private function __construct(
        public Kind $kind,
        public array $underTrained = [],
        public ?int $weeks = null,
    ) {}

    /** @param  list<MuscleGroup>  $underTrained */
    public static function restructure(array $underTrained): self
    {
        return new self(Kind::Restructure, underTrained: $underTrained);
    }

    public static function keepAllSessions(int $weeks): self
    {
        return new self(Kind::KeepAllSessions, weeks: $weeks);
    }
}
