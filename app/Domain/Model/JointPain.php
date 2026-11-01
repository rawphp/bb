<?php

namespace App\Domain\Model;

/** FB-002 answers, asked per exercise. */
enum JointPain: string
{
    case None = 'none';
    case Low = 'low';
    case Moderate = 'moderate';
    case ALot = 'aLot';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Low => 'Low pain',
            self::Moderate => 'Moderate pain',
            self::ALot => 'A lot of pain',
        };
    }
}
