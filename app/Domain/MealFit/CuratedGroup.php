<?php

namespace App\Domain\MealFit;

/** FOOD-003: curated groups, with the server's raw values. */
enum CuratedGroup: string
{
    case ProteinRecommended = 'protein_recommended';
    case ProteinSparingly = 'protein_sparingly';
    case ProteinBeforeBed = 'protein_before_bed';
    case Veggies = 'veggies';
    case CarbsRecommended = 'carbs_recommended';
    case CarbsSparingly = 'carbs_sparingly';
    case FatRecommended = 'fat_recommended';
    case FatSparingly = 'fat_sparingly';
}
