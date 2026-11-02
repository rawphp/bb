<?php

namespace App\Enums;

enum Split: string
{
    case FullBody = 'full_body';
    case UpperLower = 'upper_lower';
    case PushPullLegs = 'push_pull_legs';
    case Specialisation = 'specialisation';
}
