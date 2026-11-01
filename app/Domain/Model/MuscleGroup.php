<?php

namespace App\Domain\Model;

/** The 14 muscle groups, in the order of MESO-033. */
enum MuscleGroup: string
{
    case Chest = 'chest';
    case Back = 'back';
    case Quads = 'quads';
    case Hamstrings = 'hamstrings';
    case Glutes = 'glutes';
    case SideDelts = 'sideDelts';
    case RearDelts = 'rearDelts';
    case FrontDelts = 'frontDelts';
    case Biceps = 'biceps';
    case Triceps = 'triceps';
    case Calves = 'calves';
    case Abs = 'abs';
    case Traps = 'traps';
    case Forearms = 'forearms';

    public function title(): string
    {
        return match ($this) {
            self::Chest => 'Chest',
            self::Back => 'Back',
            self::Quads => 'Quads',
            self::Hamstrings => 'Hamstrings',
            self::Glutes => 'Glutes',
            self::SideDelts => 'Side delts',
            self::RearDelts => 'Rear delts',
            self::FrontDelts => 'Front delts',
            self::Biceps => 'Biceps',
            self::Triceps => 'Triceps',
            self::Calves => 'Calves',
            self::Abs => 'Abs',
            self::Traps => 'Traps',
            self::Forearms => 'Forearms',
        };
    }
}
