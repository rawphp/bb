<?php

namespace App\Enums;

enum Split: string
{
    case FullBody = 'full_body';
    case UpperLower = 'upper_lower';
    case PushPullLegs = 'push_pull_legs';
    case Specialisation = 'specialisation';

    /** MESO-015: the split label the library and the detail show. */
    public function title(): string
    {
        return match ($this) {
            self::FullBody => 'Full body',
            self::UpperLower => 'Upper/lower',
            self::PushPullLegs => 'Push/pull/legs',
            self::Specialisation => 'Specialisation',
        };
    }
}
