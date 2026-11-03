<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use App\Domain\Safety\ScreeningItem;
use DateTimeImmutable;

/**
 * Setup progress (ONB-002): the current step and every answer so far, as plain values. `App\Models\OnboardingDraft`
 * stores it, one row per user; the Onboarding rules read it. Dates are calendar days, at midnight in UTC.
 *
 * What is worked out from the answers (the last day, the target weight, the end date) is not here.
 */
final readonly class Draft
{
    /**
     * @param  int  $step  the spec's step, 1 to 13, or 14 for the review (ONB-018)
     * @param  ?float  $heightCm  in cm whatever the display unit (ONB-004)
     * @param  ?float  $weightKg  in kg whatever the display unit (ONB-004)
     * @param  list<ScreeningItem>  $screening  the items the user ticked, in the spec's order (SAFE-001)
     * @param  ?float  $ratePercentPerWeek  signed as TBL-010: fat loss is negative (ONB-008)
     * @param  list<TrainingDayChoice>  $trainingDays  in weekday order (ONB-011)
     * @param  ?int  $templateId  the chosen template when `trainingPlan` is a template (ONB-012)
     * @param  list<StepRangeChoice>  $stepRanges  empty until the user changes a range; the defaults stand in (ONB-013)
     * @param  list<BusyPeriodChoice>  $busyPeriods  ONB-014
     * @param  list<WeighInChoice>  $weighIns  in weekday order (ONB-015)
     * @param  ?int  $reviewWeekday  ISO; null until the user picks one, so the default follows the weigh-ins (ONB-016)
     */
    public function __construct(
        public int $step = 1,
        // Step 1 (ONB-003, ONB-021)
        public ?PhaseType $goal = null,
        public ?bool $finishedDietRecently = null,
        // Step 2 (ONB-004)
        public string $name = '',
        public ?Sex $sex = null,
        public ?DateTimeImmutable $birthDate = null,
        public ?float $heightCm = null,
        public ?float $weightKg = null,
        public WeightUnit $weightUnit = WeightUnit::Kg,
        public HeightUnit $heightUnit = HeightUnit::Cm,
        // Step 3 (SAFE-001)
        public array $screening = [],
        // Step 4 (ONB-006)
        public ?bool $usesGLP1 = null,
        // Step 5 (ONB-007)
        public ?BodyFatBucket $bodyFatBucket = null,
        // Step 6 (ONB-008)
        public ?float $ratePercentPerWeek = null,
        public bool $customRate = false,
        public ?DateTimeImmutable $startDate = null,
        public ?int $weeks = null,
        // Step 7 (ONB-010)
        public ?ExperienceLevel $experience = null,
        // Step 8 (ONB-011)
        public array $trainingDays = [],
        // Step 9 (ONB-012)
        public ?TrainingPlanKind $trainingPlan = null,
        public ?int $templateId = null,
        // Step 10 (ONB-013)
        public array $stepRanges = [],
        // Step 11 (ONB-014): one window for every day
        public int $eatingStartMinutes = 7 * 60,
        public int $eatingEndMinutes = 20 * 60,
        public int $mealCount = 5,
        public array $busyPeriods = [],
        // Step 12 (ONB-015, ONB-016)
        public array $weighIns = [],
        public ?int $reviewWeekday = null,
        // Step 13 (ONB-017)
        public bool $choosesOwnCalories = false,
        public ?int $ownDailyKcal = null,
    ) {}

    /** The same draft with the named fields changed, as `with(step: 2, name: 'Sam')`. */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
