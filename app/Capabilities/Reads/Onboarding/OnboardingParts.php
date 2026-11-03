<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingChoice;
use App\Capabilities\Onboarding\Data\OnboardingDateField;
use App\Capabilities\Onboarding\Data\OnboardingSlider;
use App\Capabilities\Onboarding\Data\OnboardingStatus;
use App\Capabilities\Onboarding\Data\OnboardingTime;
use App\Domain\Onboarding\OnboardingText;
use DateTimeImmutable;

/** The small shapes every onboarding_show section is built from. */
final class OnboardingParts
{
    /** @param  list<OnboardingStatus>  $badges */
    public static function choice(string $value, string $title, bool $selected, string $detail = '', bool $enabled = true, array $badges = []): OnboardingChoice
    {
        return new OnboardingChoice(
            value: $value, title: $title, detail: $detail, is_selected: $selected, is_enabled: $enabled, badges: $badges,
        );
    }

    public static function status(string $text, string $tone): OnboardingStatus
    {
        return new OnboardingStatus(text: $text, tone: $tone);
    }

    public static function time(int $minutes): OnboardingTime
    {
        return new OnboardingTime(minutes: $minutes, text: OnboardingText::timeText($minutes));
    }

    public static function slider(int|float $value, int|float $min, int|float $max, int|float $step, string $text): OnboardingSlider
    {
        return new OnboardingSlider(value: (float) $value, min: (float) $min, max: (float) $max, step: (float) $step, text: $text);
    }

    public static function date(?DateTimeImmutable $date, DateTimeImmutable $initial, ?DateTimeImmutable $min, ?DateTimeImmutable $max, bool $locked = false): OnboardingDateField
    {
        return new OnboardingDateField(
            date: $date?->format('Y-m-d'),
            text: $date === null ? '' : OnboardingText::dateText($date),
            initial: $initial->format('Y-m-d'),
            min: $min?->format('Y-m-d'),
            max: $max?->format('Y-m-d'),
            is_locked: $locked,
        );
    }

    /** One decimal, as the screens show a weight or a height. */
    public static function oneDecimal(float $value): float
    {
        return round($value, 1);
    }
}
