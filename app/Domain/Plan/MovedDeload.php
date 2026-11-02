<?php

namespace App\Domain\Plan;

/** A deload flag moving from one plan week to another when its mesocycle restarts (SCH-058, PLAN-022). */
final readonly class MovedDeload
{
    public function __construct(
        public int $from,
        public int $to,
    ) {}
}
