<?php

namespace App\Domain\Onboarding;

use App\Domain\Defaults\TBL010Phases;
use App\Domain\Defaults\TBL010Phases\RatePreset;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;
use App\Domain\Phases\PhaseWarnings;
use DateTimeImmutable;

/**
 * Step 6, rate and length (ONB-008, ONB-009, TBL-010), the read members of rp-clone's OnboardingModel+RateLength.
 *
 * The rate is the anchor. The exclusive end is the start plus 7 × weeks days (as `Phase::$end`) and the target weight is
 * weight × (1 + rate / 100 × weeks); neither is stored.
 */
final readonly class OnboardingRateLength
{
    public const CUSTOM_OPTION = 'Custom';

    /** TBL-010 has no maximum length for Maintenance; the slider stops at a year. */
    public const MAXIMUM_OPEN_ENDED_WEEKS = 52;

    public function __construct(private Draft $draft, private DateTimeImmutable $today) {}

    // Rate presets

    /** @return list<RateOption> */
    public function rateOptions(): array
    {
        return $this->draft->goal === null ? [] : self::rateOptionsFor($this->draft->goal);
    }

    /**
     * The presets, then Custom. Muscle gain offers its three experience presets by name: experience is not asked
     * until step 7.
     *
     * @return list<RateOption>
     */
    public static function rateOptionsFor(PhaseType $goal): array
    {
        $options = $goal === PhaseType::MuscleGain
            ? array_map(function (ExperienceLevel $level) {
                $rate = TBL010Phases::muscleGainRatePercentPerWeek($level);

                return new RateOption(ucfirst($level->value).' '.sprintf('%g', $rate).'%', $rate);
            }, ExperienceLevel::cases())
            : array_map(fn (RatePreset $preset) => new RateOption($preset->name, $preset->ratePercentPerWeek), TBL010Phases::row($goal)->presets);

        foreach ($options as $option) {
            if ($option->ratePercentPerWeek === null) {
                return $options;
            }
        }

        return [...$options, new RateOption(self::CUSTOM_OPTION, null)];
    }

    /** The preset whose rate is the stored one, or Custom. */
    public function selectedRateOptionName(): string
    {
        if ($this->draft->customRate) {
            return self::CUSTOM_OPTION;
        }
        foreach ($this->rateOptions() as $option) {
            if ($option->ratePercentPerWeek !== null && $option->ratePercentPerWeek === $this->draft->ratePercentPerWeek) {
                return $option->name;
            }
        }

        return self::CUSTOM_OPTION;
    }

    /** The custom-rate slider's range. Fat loss reads as a positive "% of body weight per week". */
    public function rateSliderRange(): ClosedRange
    {
        $range = TBL010Phases::row($this->draft->goal ?? PhaseType::Maintenance)->rateRangePercentPerWeek;

        return $this->draft->goal === PhaseType::FatLoss ? new ClosedRange(-$range->upper, -$range->lower) : $range;
    }

    public function rateSliderValue(): float
    {
        $value = ($this->draft->ratePercentPerWeek ?? 0.0) * ($this->draft->goal === PhaseType::FatLoss ? -1 : 1);

        return $value + 0.0;
    }

    // Dates and length

    /** ONB-008: the default start; ONB-020: the earliest a plan starts. */
    public function tomorrow(): DateTimeImmutable
    {
        return OnboardingDates::tomorrow($this->today);
    }

    /** The last day of the phase, which the screen shows as the end date. */
    public function lastDay(): ?DateTimeImmutable
    {
        $end = $this->endDate();

        return $end === null ? null : OnboardingDates::addingDays($end, -1);
    }

    /** The first day after the phase (the exclusive boundary, as `Phase::$end`). */
    public function endDate(): ?DateTimeImmutable
    {
        $start = $this->draft->startDate;
        $weeks = $this->draft->weeks;

        return $start === null || $weeks === null ? null : OnboardingDates::addingDays($start, 7 * $weeks);
    }

    public function targetWeightKg(): ?float
    {
        $weight = $this->draft->weightKg;
        $rate = $this->draft->ratePercentPerWeek;
        $weeks = $this->draft->weeks;

        return $weight === null || $rate === null || $weeks === null ? null : $weight * (1 + $rate / 100 * $weeks);
    }

    /** The target in the unit on screen. */
    public function targetWeightValue(): ?float
    {
        $target = $this->targetWeightKg();

        return $target === null ? null : OnboardingText::weightIn($target, $this->draft->weightUnit);
    }

    /** With a rate of 0 there is no target to move to. */
    public function canEditTarget(): bool
    {
        return $this->draft->weightKg !== null && ($this->draft->ratePercentPerWeek ?? 0.0) != 0.0;
    }

    /** ONB-009: TBL-010's length limits. */
    public function weeksRange(): ClosedRange
    {
        return self::weeksRangeFor($this->draft->goal ?? PhaseType::Maintenance);
    }

    public static function weeksRangeFor(PhaseType $goal): ClosedRange
    {
        $row = TBL010Phases::row($goal);

        return new ClosedRange($row->minWeeks, $row->maxWeeks ?? self::MAXIMUM_OPEN_ENDED_WEEKS);
    }

    /** ONB-009: shown under the length slider, for Fat loss and Muscle gain. */
    public function recommendedRangeText(): ?string
    {
        $goal = $this->draft->goal;
        $range = $goal === PhaseType::FatLoss || $goal === PhaseType::MuscleGain ? TBL010Phases::row($goal)->recommendedWeeks : null;

        return $range === null ? null : "Recommended: {$range->lower} to {$range->upper} weeks";
    }

    // Warnings (TBL-010)

    /**
     * Fat-loss cautions. They never block continuing.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $weeks = $this->draft->weeks;
        if ($this->draft->goal !== PhaseType::FatLoss || $weeks === null) {
            return [];
        }
        $messages = [];
        if ($weeks < TBL010Phases::MINI_CUT_SUGGESTED_BELOW_FAT_LOSS_WEEKS) {
            $messages[] = 'Under 6 weeks, a Mini-cut may suit you better.';
        }
        if ($weeks > TBL010Phases::FAT_LOSS_WARN_ABOVE_WEEKS) {
            $messages[] = 'Fat-loss phases over 12 weeks are hard to sustain.';
        }
        $rate = $this->draft->ratePercentPerWeek;
        if ($rate !== null && -$rate * $weeks > TBL010Phases::MAX_FAT_LOSS_PERCENT_OF_START_BODYWEIGHT) {
            $messages[] = 'This plan loses more than 10% of your starting weight. Consider a slower rate or a shorter phase.';
        }

        return $messages;
    }

    // Defaults: a new goal starts step 6 from these.

    /** The preset marked as the default: Highest success, Intermediate, Hold. */
    public static function defaultRate(PhaseType $goal): float
    {
        return $goal === PhaseType::MuscleGain
            ? TBL010Phases::muscleGainRatePercentPerWeek(ExperienceLevel::Intermediate)
            : (TBL010Phases::row($goal)->presets[0]->ratePercentPerWeek ?? 0.0);
    }

    /** Twelve weeks, or Maintenance's suggested length, inside the goal's limits. */
    public static function defaultWeeks(PhaseType $goal): int
    {
        $weeks = $goal === PhaseType::Maintenance ? PhaseWarnings::SUGGESTED_MAINTENANCE_WEEKS : 12;
        $range = self::weeksRangeFor($goal);

        return (int) min(max($weeks, $range->lower), $range->upper);
    }
}
