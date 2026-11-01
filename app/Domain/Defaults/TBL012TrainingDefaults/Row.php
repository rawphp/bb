<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

use App\Domain\Model\ClosedRange;

final readonly class Row
{
    /**
     * @param  ?float  $weeklyLoadStepPercent  null where loads are held
     * @param  bool  $loadStepUsesSmallestPlate  fat loss: "1%, smallest plate"
     * @param  float  $copyLoadRisePercent  MESO-061: load rise over the old week 1 when copying a mesocycle
     * @param  ClosedRange  $copyLoadRiseAdvancedPercent  the same for advanced lifters
     */
    public function __construct(
        public PriorityPreset $priorityPreset,
        public TemplateFit $templateFit,
        public LandmarkOffset $startVolume,
        public LandmarkOffset $ceiling,
        public int $maxSetsAddedPerSessionPerWeek,
        public ?float $weeklyLoadStepPercent,
        public bool $loadStepUsesSmallestPlate,
        public float $copyLoadRisePercent,
        public ClosedRange $copyLoadRiseAdvancedPercent,
        public RepRangeBias $repRangeBias,
        public int $minimumFrequencyPerWeek,
        public DeloadCalories $deloadCalories,
    ) {}
}
