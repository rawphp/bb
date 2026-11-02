<?php

namespace Tests\Unit\Domain\Review;

use App\Domain\Macros\WeekTargets;
use App\Domain\Model\DayType;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile;
use App\Domain\Review\CalorieRecommendation;
use App\Domain\Review\CalorieReview;
use App\Domain\Review\ReviewWeek;
use App\Domain\Weight\TrendWeight;
use Tests\Unit\Domain\Macros\MacroFixtures;

/**
 * A multi-week review (rp-clone's CalorieScenario in CalorieFixtures): the weeks, a trend with exact weekly rates,
 * and the phase, ready to ask for a recommendation. Fields are public so a test can change them, as in Swift.
 */
final class CalorieScenario
{
    /**
     * @param  list<ReviewWeek>  $weeks
     * @param  list<float>  $rates  the trend's weekly rate in each plan week, the last one carrying on
     * @param  array<int, float>  $readingFactors
     * @param  int|null  $trendThrough  cut the trend short, to test a week without enough trend
     */
    public function __construct(
        public array $weeks,
        public PhaseType $phaseType = PhaseType::FatLoss,
        public float $targetRate = -0.5,
        public int $phaseStartDay = 0,
        public array $rates = [-0.5],
        public float $startKg = 80.0,
        public float $heightCm = 180.0,
        public float $formulaKcal = 2600.0,
        public int $plannedSteps = 6000,
        public array $readingFactors = [],
        public ?int $trendThrough = null,
    ) {}

    public function lastDay(): int
    {
        return 7 * count($this->weeks) - 1;
    }

    public function current(): float
    {
        return $this->weeks[count($this->weeks) - 1]->targetKcal;
    }

    public function trend(): TrendWeight
    {
        return CalorieFixtures::shapedTrend(
            $this->rates, $this->trendThrough ?? $this->lastDay(), $this->startKg, $this->readingFactors,
        );
    }

    /** The trend weight on the reviewed week's last day. */
    public function trendKg(): float
    {
        return $this->trend()->trendKg(CalorieFixtures::day($this->lastDay()));
    }

    public function phase(): Phase
    {
        return new Phase(
            id: 'phase',
            type: $this->phaseType,
            start: CalorieFixtures::day($this->phaseStartDay),
            end: CalorieFixtures::day($this->phaseStartDay + 400),
            targetRatePercentPerWeek: $this->targetRate,
        );
    }

    public function profile(): Profile
    {
        return MacroFixtures::profile(heightCm: $this->heightCm, weightKg: $this->trend()->currentKg());
    }

    /** The reviewed week's targets, as one flat maintenance-style week at the week's average. */
    public function currentTargets(): WeekTargets
    {
        return MacroFixtures::targets(
            profile: $this->profile(),
            phase: MacroFixtures::phase(PhaseType::Maintenance),
            days: MacroFixtures::uniformDays($this->current(), DayType::Moderate),
        );
    }

    /** REV-023: the calorie level that hits the target rate, before any cap. */
    public function level(CalorieRecommendation $recommendation): float
    {
        return $recommendation->expenditure->kcal + CalorieFixtures::kcal($this->targetRate, $this->trendKg());
    }

    public function recommend(): CalorieRecommendation
    {
        return CalorieReview::recommend(
            phase: $this->phase(),
            weeks: $this->weeks,
            trend: $this->trend(),
            currentTargets: $this->currentTargets(),
            profile: $this->profile(),
            formulaExpenditureKcal: $this->formulaKcal,
            plannedDailySteps: $this->plannedSteps,
        );
    }
}
