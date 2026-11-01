<?php

namespace App\Domain\Model;

/** FB-004 answers, asked per muscle. */
enum Pump: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case Amazing = 'amazing';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low pump',
            self::Moderate => 'Moderate pump',
            self::Amazing => 'Amazing pump',
        };
    }
}
