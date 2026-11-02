<?php

namespace App\Domain\Mesocycle;

/** MESO-016: how much stimulus an exercise gives for the fatigue it costs. */
enum StimulusToFatigue: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** Higher is better: low 0, medium 1, high 2. */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
        };
    }
}
