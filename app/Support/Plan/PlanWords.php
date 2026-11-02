<?php

namespace App\Support\Plan;

use App\Domain\Defaults\TBL012TrainingDefaults\Landmark;
use App\Domain\Defaults\TBL012TrainingDefaults\LandmarkOffset;
use App\Domain\Defaults\TBL012TrainingDefaults\PriorityPreset;
use App\Domain\Defaults\TBL012TrainingDefaults\Row;
use App\Domain\Model\PhaseType;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PhaseRefusal\Reason;
use DateTimeImmutable;

/** How the Plan screens say things (rp-clone's PlanText, EventCountdown, TrainingDefaultsText and WeightDisplay). */
final class PlanWords
{
    /** "Lose 0.75% a week", "Hold weight", "Gain 0.375% a week". */
    public static function rate(float $rate): string
    {
        if ($rate == 0.0) {
            return 'Hold weight';
        }
        $number = self::number(abs($rate));

        return $rate > 0 ? "Gain {$number}% a week" : "Lose {$number}% a week";
    }

    /** "31 Aug". */
    public static function day(DateTimeImmutable $day): string
    {
        return $day->format('j M');
    }

    /** "31 Aug – 22 Nov" from the first day and the day after the last; "31 Aug – no end" with no end. */
    public static function dates(DateTimeImmutable $start, ?DateTimeImmutable $end): string
    {
        return self::day($start).' – '.($end === null ? 'no end' : self::day($end->modify('-1 day')));
    }

    /** A week's days: "5 – 11 Oct", or "28 Sep – 4 Oct" across a month. */
    public static function week(DateTimeImmutable $start, int $dayCount): string
    {
        $last = $start->modify('+'.($dayCount - 1).' days');

        return $start->format('M') === $last->format('M')
            ? $start->format('j').' – '.self::day($last)
            : self::day($start).' – '.self::day($last);
    }

    /** "1 week", "12 weeks". */
    public static function weeks(int $weeks): string
    {
        return $weeks === 1 ? '1 week' : "{$weeks} weeks";
    }

    /** PH-043. */
    public static function eventCountdown(int $days): string
    {
        return match ($days) {
            0 => 'Your event is today',
            1 => 'Tomorrow is your event',
            default => "{$days} days to your event",
        };
    }

    /** One decimal in the user's unit: "81.4 kg". */
    public static function weight(float $kg, WeightUnit $unit): string
    {
        return number_format(self::inUnit($kg, $unit), 1).' '.$unit->value;
    }

    /** A signed change, one decimal in the user's unit: "-1.6 kg". */
    public static function weightChange(float $kg, WeightUnit $unit): string
    {
        $value = round(self::inUnit($kg, $unit), 1);

        return ($value >= 0 ? '+' : '-').number_format(abs($value), 1).' '.$unit->value;
    }

    /** What the user reads when TBL-010 or PLAN-006 refuses a phase. */
    public static function refusal(PhaseRefusal $refusal, PhaseType $type): string
    {
        $title = $type->title();

        return match ($refusal->reason) {
            Reason::EndDateRequired => 'Only Maintenance can run with no end date.',
            Reason::TooShort => "A {$title} phase must run at least {$refusal->minimumWeeks} weeks.",
            Reason::TooLong => "A {$title} phase can run at most {$refusal->maximumWeeks} weeks.".($type->isFatLoss() ? ' Diet-break weeks do not count.' : ''),
            Reason::RateOutOfRange => self::rateRefusal($refusal, $title),
            Reason::NotOnWeekBoundary => 'A phase must start on the first day of a plan week.',
            Reason::PreviousPhaseHasNoEnd => 'A phase with no end cannot have phases after it.',
        };
    }

    /**
     * TBL-012 in words (PH-008, PH-039).
     *
     * @return list<string>
     */
    public static function trainingLines(Row $row): array
    {
        $sets = $row->maxSetsAddedPerSessionPerWeek;

        return [
            match ($row->priorityPreset) {
                PriorityPreset::AllEmphasize => 'All muscles Emphasize',
                PriorityPreset::AllMaintain => 'All muscles Maintain',
                PriorityPreset::AllGrow => 'All muscles Grow',
                PriorityPreset::UpToTwoEmphasizeRestGrowOrMaintain => 'Up to 2 muscles Emphasize, the rest Grow or Maintain',
            },
            self::volume('Volume starts at', $row->startVolume),
            self::volume('Volume ceiling:', $row->ceiling),
            $sets === 0 ? 'No sets added from week to week' : "Up to {$sets} set".($sets === 1 ? '' : 's').' added per session each week',
            $row->weeklyLoadStepPercent === null
                ? 'Loads are held'
                : 'Loads go up '.self::number($row->weeklyLoadStepPercent).'% a week'.($row->loadStepUsesSmallestPlate ? ', at least the smallest plate' : ''),
            "Each muscle at least {$row->minimumFrequencyPerWeek}x a week",
            'Deload weeks at maintenance calories',
        ];
    }

    private static function volume(string $lead, LandmarkOffset $offset): string
    {
        if ($offset->landmark === Landmark::Mv) {
            return "{$lead} maintenance volume (MV)";
        }
        $name = strtoupper($offset->landmark->value);
        $mark = $offset->sets === 0 ? $name : $name.' '.($offset->sets > 0 ? '+' : '-').abs($offset->sets);

        return "{$lead} {$mark} sets";
    }

    private static function rateRefusal(PhaseRefusal $refusal, string $title): string
    {
        $range = $refusal->rateRange;
        if ($range->lower == -$range->upper) {
            return "The rate for {$title} must stay within ±".self::number($range->upper).'% a week.';
        }
        [$first, $second] = [abs($range->lower), abs($range->upper)];

        return "The rate for {$title} must be between ".self::number(min($first, $second)).'% and '.self::number(max($first, $second)).'% a week.';
    }

    /** "%g": no trailing zeros. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }

    private static function inUnit(float $kg, WeightUnit $unit): float
    {
        return $unit === WeightUnit::Kg ? $kg : UnitConversion::kgToLb($kg);
    }
}
