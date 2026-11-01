<?php

namespace App\Domain\Model;

/** FB-005 answers, asked per muscle. */
enum VolumeFeedback: string
{
    case NotEnough = 'notEnough';
    case JustRight = 'justRight';
    case PushedMyLimits = 'pushedMyLimits';
    case TooMuch = 'tooMuch';

    public function label(): string
    {
        return match ($this) {
            self::NotEnough => 'Not enough',
            self::JustRight => 'Just right',
            self::PushedMyLimits => 'Pushed my limits',
            self::TooMuch => 'Too much',
        };
    }
}
