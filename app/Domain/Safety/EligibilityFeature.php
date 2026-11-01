<?php

namespace App\Domain\Safety;

enum EligibilityFeature: string
{
    case Training = 'training';
    case WeighIns = 'weighIns';
    case TrendWeight = 'trendWeight';
    case NutritionTargets = 'nutritionTargets';
}
