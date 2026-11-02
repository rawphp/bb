<?php

namespace App\Capabilities\Reads\Progress;

use App\Capabilities\Progress\Data\ProgressEmpty;
use App\Capabilities\Progress\Data\ProgressScope as ScopeChoice;
use App\Capabilities\Progress\Data\ProgressShowOutput;
use App\Domain\Progress\ProgressScope;
use App\Support\Progress\ProgressSnapshot;

/** The Progress tab (ANA-001 to ANA-011, ONB-032). */
final class ProgressPayload
{
    public static function build(ProgressSnapshot $snapshot, ProgressScope $scope): ProgressShowOutput
    {
        // ONB-032, WT-012: the chart and summary wait for 4 weigh-ins; every other card shows without them.
        $enough = $snapshot->trend()->hasEnoughDataForChart();
        $chart = $enough ? ProgressChartPayload::chart($snapshot, $scope) : null;
        $summary = $enough ? ProgressChartPayload::summary($snapshot, $scope) : null;

        return new ProgressShowOutput(
            title: 'Progress',
            scopes: array_map(
                fn (ProgressScope $choice) => new ScopeChoice(self::wire($choice), $choice->title(), $choice === $scope),
                [ProgressScope::CurrentPhase, ProgressScope::WholePlan],
            ),
            empty: $enough ? null : new ProgressEmpty('Log 4 weigh-ins to see your trend', 'Log a weigh-in'),
            scope_message: $enough && $chart === null ? self::scopeMessage($scope) : null,
            chart: $chart,
            summary: $summary === null ? [] : ProgressChartPayload::lines($summary, $snapshot),
            share: $summary === null ? null : ProgressChartPayload::share($summary, $snapshot),
            this_week: ProgressWeekPayload::thisWeek($snapshot),
            review_card: ProgressWeekPayload::reviewCard($snapshot),
            steps: ProgressWeekPayload::steps($snapshot),
            phase_history: ProgressHistoryPayload::phases($snapshot),
            mesocycle_history: ProgressHistoryPayload::mesocycles($snapshot),
            exercises: ProgressHistoryPayload::exercises($snapshot),
        );
    }

    /** The input's `scope` as the rule's scope: `plan` is the whole plan, anything else the current phase. */
    public static function scope(string $wire): ProgressScope
    {
        return $wire === 'plan' ? ProgressScope::WholePlan : ProgressScope::CurrentPhase;
    }

    private static function wire(ProgressScope $scope): string
    {
        return $scope === ProgressScope::WholePlan ? 'plan' : 'phase';
    }

    /** Shown in place of the chart when the chosen range has no weigh-ins; the scope is not switched silently. */
    private static function scopeMessage(ProgressScope $scope): string
    {
        return match ($scope) {
            ProgressScope::CurrentPhase => 'No weigh-ins in this phase yet',
            ProgressScope::WholePlan => 'No weigh-ins in this plan yet',
        };
    }
}
