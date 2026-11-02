<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\TargetRefusal;
use DomainException;

/** Thrown when a week cannot be built; the reason says why, with the refusal or day it came from. */
final class WeekBuildRefusal extends DomainException
{
    private function __construct(
        public readonly WeekBuildRefusalReason $reason,
        public readonly ?TargetRefusal $targets = null,
        public readonly ?MoveRefusal $move = null,
        public readonly ?int $dayIndex = null,
    ) {
        parent::__construct("Week build refused: {$reason->value}", 0, $targets ?? $move);
    }

    public static function noPhase(): self
    {
        return new self(WeekBuildRefusalReason::NoPhase);
    }

    public static function targets(TargetRefusal $refusal): self
    {
        return new self(WeekBuildRefusalReason::Targets, targets: $refusal);
    }

    public static function mealsDoNotFit(int $dayIndex): self
    {
        return new self(WeekBuildRefusalReason::MealsDoNotFit, dayIndex: $dayIndex);
    }

    public static function move(MoveRefusal $refusal): self
    {
        return new self(WeekBuildRefusalReason::Move, move: $refusal);
    }
}
