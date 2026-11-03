<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingRateLengthSection;
use App\Capabilities\Onboarding\Data\OnboardingTargetWeight;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingRateLength;
use App\Domain\Onboarding\OnboardingText;
use App\Domain\Onboarding\RateOption;
use App\Support\Plan\PlanWords;

/** Step 6 of onboarding_show: rate and length (ONB-008, ONB-009). */
final class OnboardingRateLengthPayload
{
    /** The custom rate slider's smallest move, in percent of body weight. */
    private const RATE_STEP = 0.025;

    public const NO_TARGET_NOTE = 'A steady weight has no target to change.';

    public static function build(Draft $draft, OnboardingRateLength $rateLength): OnboardingRateLengthSection
    {
        $rate = $draft->ratePercentPerWeek;
        $selected = $rate === null ? null : $rateLength->selectedRateOptionName();
        $slider = $rateLength->rateSliderRange();
        $weeks = $rateLength->weeksRange();
        $tomorrow = $rateLength->tomorrow();
        $lastDay = $rateLength->lastDay();
        $targetKg = $rateLength->targetWeightKg();
        $target = $rateLength->targetWeightValue();

        return new OnboardingRateLengthSection(
            options: array_map(fn (RateOption $option) => OnboardingParts::choice(
                $option->name, $option->name, $option->name === $selected, $option->ratePercentPerWeek === null ? '' : self::rateText($option->ratePercentPerWeek),
            ), $rateLength->rateOptions()),
            is_custom: $selected === OnboardingRateLength::CUSTOM_OPTION,
            custom_rate: OnboardingParts::slider(
                $rateLength->rateSliderValue(), $slider->lower, $slider->upper, self::RATE_STEP, $rate === null ? '' : self::rateText($rate),
            ),
            weeks: OnboardingParts::slider(
                $draft->weeks ?? $weeks->lower, $weeks->lower, $weeks->upper, 1, $draft->weeks === null ? '' : PlanWords::weeks($draft->weeks),
            ),
            recommended_text: $rateLength->recommendedRangeText() ?? '',
            start_date: OnboardingParts::date($draft->startDate, $draft->startDate ?? $tomorrow, $tomorrow, null),
            last_day: OnboardingParts::date($lastDay, $lastDay ?? $tomorrow, $tomorrow, null),
            target_weight: new OnboardingTargetWeight(
                value: $target === null ? null : OnboardingParts::oneDecimal($target),
                unit: $draft->weightUnit->value,
                text: $targetKg === null ? '' : OnboardingText::weightText($targetKg, $draft->weightUnit),
                can_edit: $rateLength->canEditTarget(),
                note: ($rate ?? 0.0) == 0.0 ? self::NO_TARGET_NOTE : '',
            ),
        );
    }

    /** "Lose 0.75% of body weight per week", "Gain 0.375% of body weight per week" or "Hold weight". */
    private static function rateText(float $rate): string
    {
        if ($rate == 0.0) {
            return 'Hold weight';
        }
        $number = sprintf('%g', abs($rate));

        return ($rate > 0 ? 'Gain' : 'Lose')." {$number}% of body weight per week";
    }
}
