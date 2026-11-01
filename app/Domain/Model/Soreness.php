<?php

namespace App\Domain\Model;

/** FB-003 answers, asked per muscle. */
enum Soreness: string
{
    case NeverGotSore = 'neverGotSore';
    case HealedAWhileAgo = 'healedAWhileAgo';
    case HealedJustOnTime = 'healedJustOnTime';
    case StillSore = 'stillSore';

    public function label(): string
    {
        return match ($this) {
            self::NeverGotSore => 'Never got sore',
            self::HealedAWhileAgo => 'Healed a while ago',
            self::HealedJustOnTime => 'Healed just on time',
            self::StillSore => 'I\'m still sore!',
        };
    }
}
