<?php

namespace App\Enums;

enum MuscleGroup: string
{
    case Chest = 'chest';
    case Back = 'back';
    case Quads = 'quads';
    case Hamstrings = 'hamstrings';
    case Glutes = 'glutes';
    case SideDelts = 'side_delts';
    case RearDelts = 'rear_delts';
    case FrontDelts = 'front_delts';
    case Biceps = 'biceps';
    case Triceps = 'triceps';
    case Calves = 'calves';
    case Abs = 'abs';
    case Traps = 'traps';
    case Forearms = 'forearms';
}
