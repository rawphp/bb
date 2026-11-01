<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL021VolumeLandmarks\Landmarks;
use App\Domain\Model\MuscleGroup;

/** TBL-021 Volume landmarks, sets per muscle per week (intermediate defaults). */
final class TBL021VolumeLandmarks
{
    /** Beginners: MEV -2, MRV -4. */
    public const BEGINNER_MEV_OFFSET = -2;

    public const BEGINNER_MRV_OFFSET = -4;

    /** Advanced: MEV +2. */
    public const ADVANCED_MEV_OFFSET = 2;

    /** Starting volume is never below MV and never below this. */
    public const STARTING_VOLUME_FLOOR_SETS = 0;

    /** A muscle the mesocycle trains directly gets at least this many sets per session. */
    public const MINIMUM_SETS_PER_SESSION_FOR_DIRECTLY_TRAINED_MUSCLE = 2;

    public static function landmarks(MuscleGroup $muscle): Landmarks
    {
        return match ($muscle) {
            MuscleGroup::Chest => new Landmarks(8, 10, 22),
            MuscleGroup::Back => new Landmarks(8, 10, 25),
            MuscleGroup::Quads => new Landmarks(6, 8, 20),
            MuscleGroup::Hamstrings => new Landmarks(4, 6, 20),
            MuscleGroup::Glutes => new Landmarks(0, 0, 16),
            MuscleGroup::SideDelts => new Landmarks(0, 8, 26),
            MuscleGroup::RearDelts => new Landmarks(0, 6, 26),
            MuscleGroup::FrontDelts => new Landmarks(0, 0, 12),
            MuscleGroup::Biceps => new Landmarks(5, 8, 26),
            MuscleGroup::Triceps => new Landmarks(4, 6, 18),
            MuscleGroup::Calves => new Landmarks(6, 8, 20),
            MuscleGroup::Abs => new Landmarks(0, 0, 25),
            MuscleGroup::Traps => new Landmarks(0, 0, 26),
            MuscleGroup::Forearms => new Landmarks(0, 0, 25),
        };
    }
}
