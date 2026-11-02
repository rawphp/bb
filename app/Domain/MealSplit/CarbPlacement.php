<?php

namespace App\Domain\MealSplit;

/** NUT-037: where carbs sit on a training day. */
enum CarbPlacement: string
{
    /** Default: TBL-033 shares as printed. */
    case TrainingCentred = 'training_centred';

    /** Moves up to 10 points of carbs from the pre-workout meal to the bedtime meal. */
    case EveningWeighted = 'evening_weighted';
}
