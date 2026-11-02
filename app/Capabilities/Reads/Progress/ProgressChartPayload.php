<?php

namespace App\Capabilities\Reads\Progress;

use App\Capabilities\Progress\Data\ChartPoint;
use App\Capabilities\Progress\Data\GoalPoint;
use App\Capabilities\Progress\Data\ProgressChart;
use App\Capabilities\Progress\Data\ProgressShare;
use App\Capabilities\Support\Line;
use App\Capabilities\Support\Tone;
use App\Domain\Model\UnitConversion;
use App\Domain\Progress\ChartGoalPoint;
use App\Domain\Progress\ChartWeightPoint;
use App\Domain\Progress\ProgressChartData;
use App\Domain\Progress\ProgressScope;
use App\Domain\Progress\ProgressShare as DomainShare;
use App\Domain\Progress\ProgressSummary;
use App\Models\WeighIn;
use App\Support\Plan\PlanWords;
use App\Support\Progress\ProgressSnapshot;
use App\Support\Today\TodaySnapshot;

/** The weight chart, its summary and the share text (ANA-001, ANA-002, ANA-010, WT-021). */
final class ProgressChartPayload
{
    /** WT-021: the waist line needs this many readings. */
    private const MINIMUM_WAIST_READINGS = 4;

    /** ANA-001: null when the scope has no trend to draw. */
    public static function chart(ProgressSnapshot $snapshot, ProgressScope $scope): ?ProgressChart
    {
        $data = ProgressChartData::make(
            $scope, $snapshot->trend(), $snapshot->phases(), $snapshot->planStart(), $snapshot->today(), $snapshot->zone(),
        );
        if ($data->trend === []) {
            return null;
        }
        $unit = $snapshot->weightUnit();
        $first = $data->trend[0];
        $last = $data->trend[array_key_last($data->trend)];

        return new ProgressChart(
            unit: $unit->value,
            weights: array_map(fn (ChartWeightPoint $point) => new ChartPoint($point->day->format('Y-m-d'), round(ProgressWords::inUnit($point->kg, $unit), 1)), $data->weights),
            trend: array_map(fn (ChartWeightPoint $point) => new ChartPoint($point->day->format('Y-m-d'), round(ProgressWords::inUnit($point->kg, $unit), 2)), $data->trend),
            goal: array_map(fn (ChartGoalPoint $point) => new GoalPoint(
                $point->day->format('Y-m-d'),
                round(ProgressWords::inUnit($point->lowerKg, $unit), 2),
                round(ProgressWords::inUnit($point->goalKg, $unit), 2),
                round(ProgressWords::inUnit($point->upperKg, $unit), 2),
            ), $data->goal),
            waist_unit: $snapshot->usesCentimetres() ? 'cm' : 'in',
            waist: self::waist($snapshot, $first->day->format('Y-m-d'), $last->day->format('Y-m-d')),
            accessibility_label: 'Weight chart. Trend weight from '.PlanWords::weight($first->kg, $unit).' to '.PlanWords::weight($last->kg, $unit).'.',
        );
    }

    /**
     * WT-021: empty until the user has 4 waist readings in all; then each day of the chart that has one, taking the
     * day's earliest reading with a waist as the trend takes the earliest weigh-in.
     *
     * @return list<ChartPoint>
     */
    private static function waist(ProgressSnapshot $snapshot, string $from, string $to): array
    {
        $readings = $snapshot->weighIns->filter(fn (WeighIn $weighIn) => $weighIn->waist_cm !== null);
        if ($readings->count() < self::MINIMUM_WAIST_READINGS) {
            return [];
        }
        $byDay = [];
        foreach ($readings->sortBy(fn (WeighIn $weighIn) => TodaySnapshot::instant($weighIn->weighed_at)->getTimestamp()) as $weighIn) {
            $byDay[TodaySnapshot::instant($weighIn->weighed_at)->format('Y-m-d')] ??= (float) $weighIn->waist_cm;
        }
        ksort($byDay);
        $centimetres = $snapshot->usesCentimetres();
        $points = [];
        foreach ($byDay as $day => $cm) {
            if ($day >= $from && $day <= $to) {
                $points[] = new ChartPoint($day, round($centimetres ? $cm : $cm / UnitConversion::CM_PER_INCH, 1));
            }
        }

        return $points;
    }

    /** ANA-002: start, change so far and goal; none without a summary. */
    public static function summary(ProgressSnapshot $snapshot, ProgressScope $scope): ?ProgressSummary
    {
        return ProgressSummary::make(
            $scope, $snapshot->trend(), $snapshot->phases(), $snapshot->planStart(), $snapshot->today(), $snapshot->zone(),
        );
    }

    /** @return list<Line> */
    public static function lines(ProgressSummary $summary, ProgressSnapshot $snapshot): array
    {
        $unit = $snapshot->weightUnit();

        return [
            new Line('Start', PlanWords::weight($summary->startKg, $unit), Tone::Neutral->value),
            new Line('Change', PlanWords::weightChange($summary->changeKg(), $unit), Tone::Neutral->value),
            new Line('Goal', $summary->goalKg === null ? '–' : PlanWords::weight($summary->goalKg, $unit), Tone::Neutral->value),
        ];
    }

    /** ANA-010. */
    public static function share(ProgressSummary $summary, ProgressSnapshot $snapshot): ProgressShare
    {
        return new ProgressShare(DomainShare::TITLE, DomainShare::text($summary, $snapshot->weightUnit()));
    }
}
