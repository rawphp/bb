<?php

namespace App\Capabilities\WeighIns;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Today\TodayWords;
use App\Capabilities\Support\Line;
use App\Capabilities\WeighIns\Data\StepsRow;
use App\Capabilities\WeighIns\Data\StepsSection;
use App\Capabilities\WeighIns\Data\Weekday;
use App\Capabilities\WeighIns\Data\WeighInHelp;
use App\Capabilities\WeighIns\Data\WeighInRow;
use App\Capabilities\WeighIns\Data\WeighInSchedule as ScheduleData;
use App\Capabilities\WeighIns\Data\WeighInScheduleEntry as ScheduleEntryData;
use App\Capabilities\WeighIns\Data\WeighInsShowOutput;
use App\Capabilities\WeighIns\Data\WeighInUnits;
use App\Domain\Model\WeighIn as WeighInPoint;
use App\Domain\Placement\ClockTime;
use App\Domain\Weight\TrendWeight;
use App\Domain\WeighIns\BodyMeasures;
use App\Domain\WeighIns\StepCounts;
use App\Domain\WeighIns\WeighInSchedule;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The Weigh-ins screen (WT-001 to WT-021), every value ready to show in the user's units (CAP-015). Times are the
 * wall-clock times the weigh-ins were stored at.
 */
final class WeighInsPayload
{
    public const HELP_TITLE = 'Weighing in';

    /** WT-005. */
    public const HELP = 'Weigh yourself first thing in the morning, after the bathroom, in minimal clothing. Single readings bounce around; only the trend matters.';

    public static function build(User $user, CarbonImmutable $now): WeighInsShowOutput
    {
        $profile = Profile::query()->where('user_id', $user->id)->first();
        $weightUnit = $profile?->weight_unit === 'lb' ? 'lb' : 'kg';
        $waistUnit = BodyMeasures::waistUnit((string) ($profile->height_unit ?? 'cm'));

        $weighIns = WeighIn::query()->where('user_id', $user->id)->orderByDesc('weighed_at')->orderByDesc('id')->get()->all();
        $entries = WeighInScheduleEntry::query()->where('user_id', $user->id)->orderBy('id')->get()->all();
        $weekdays = array_map(fn (WeighInScheduleEntry $entry) => array_map('intval', $entry->weekdays ?? []), $entries);
        $trendKg = (new TrendWeight(
            array_map(fn (WeighIn $w) => new WeighInPoint((string) $w->id, $w->weighed_at->toDateTimeImmutable(), (float) $w->weight_kg), $weighIns),
            new DateTimeZone('UTC'),
        ))->currentKg();

        return new WeighInsShowOutput(
            title: 'Weigh-ins',
            trend: new Line('Trend weight', $trendKg === null ? 'No weigh-ins yet' : BodyMeasures::weightLabel($trendKg, $weightUnit), 'neutral'),
            help: new WeighInHelp(self::HELP_TITLE, self::HELP),
            units: new WeighInUnits($weightUnit, $waistUnit),
            schedule: self::schedule($entries, $weekdays),
            rows: self::rows($weighIns, $entries, $weekdays, $now, $weightUnit, $waistUnit),
            missed: new Status('Missed', 'warning'),
            empty_message: $weighIns === [] ? 'No weigh-ins yet. Tap + to log your first.' : null,
            steps: self::steps($user, $now),
        );
    }

    /**
     * @param  list<WeighInScheduleEntry>  $entries
     * @param  list<list<int>>  $weekdays  each entry's
     */
    private static function schedule(array $entries, array $weekdays): ScheduleData
    {
        $rows = [];
        foreach ($entries as $index => $entry) {
            $days = WeighInSchedule::days([$weekdays[$index]]);
            $rows[] = new ScheduleEntryData(
                weekdays: $days,
                days_label: WeighInSchedule::daysLabel($days),
                time: (string) $entry->time,
                time_label: ClockTime::fromHhmm((string) $entry->time)?->shortText() ?? (string) $entry->time,
                can_delete: WeighInSchedule::canDelete($weekdays, $index),
            );
        }

        return new ScheduleData(
            summary: WeighInSchedule::summary($weekdays),
            note: WeighInSchedule::TIME_NOTE,
            entries: $rows,
            weekdays: array_map(
                fn (int $number) => new Weekday($number, WeighInSchedule::WEEKDAYS[$number][0], WeighInSchedule::WEEKDAYS[$number][1]),
                array_keys(WeighInSchedule::WEEKDAYS),
            ),
            delete_blocked_message: WeighInSchedule::DELETE_BLOCKED,
        );
    }

    /**
     * WT-004: every weigh-in and every missed scheduled day, newest first.
     *
     * @param  list<WeighIn>  $weighIns
     * @param  list<WeighInScheduleEntry>  $entries
     * @param  list<list<int>>  $weekdays  each entry's
     * @return list<WeighInRow>
     */
    private static function rows(array $weighIns, array $entries, array $weekdays, CarbonImmutable $now, string $weightUnit, string $waistUnit): array
    {
        $rows = [];
        foreach ($weighIns as $weighIn) {
            $at = $weighIn->weighed_at->toDateTimeImmutable();
            $waist = $weighIn->waist_cm === null ? null : (float) $weighIn->waist_cm;
            $rows[] = [$at, new WeighInRow(
                id: (string) $weighIn->id,
                is_missed: false,
                date_label: self::dateLabel($at),
                time_label: self::timeLabel($at),
                weight_label: BodyMeasures::weightLabel((float) $weighIn->weight_kg, $weightUnit),
                waist_label: $waist === null ? '' : BodyMeasures::waistLabel($waist, $waistUnit),
                weighed_at: $at->format(DATE_ATOM),
                weight_field: BodyMeasures::weightField((float) $weighIn->weight_kg, $weightUnit),
                waist_field: $waist === null ? '' : BodyMeasures::waistField($waist, $waistUnit),
            )];
        }

        // A late weigh-in for a missed day starts at that day's reminder time.
        $times = [];
        foreach ($entries as $index => $entry) {
            foreach ($weekdays[$index] as $day) {
                $times[$day] ??= ClockTime::fromHhmm((string) $entry->time) ?? ClockTime::at(0);
            }
        }
        $missedDays = WeighInSchedule::missedDays(
            array_keys($times),
            array_map(fn (WeighIn $w) => $w->weighed_at->toDateTimeImmutable(), $weighIns),
            $now->toDateTimeImmutable(),
        );
        foreach ($missedDays as $day) {
            $time = $times[(int) $day->format('N')];
            $at = $day->setTime($time->hour(), $time->minute());
            $rows[] = [$at, new WeighInRow(
                id: 'missed-'.$day->format('Y-m-d'),
                is_missed: true,
                date_label: self::dateLabel($at),
                time_label: '',
                weight_label: '',
                waist_label: '',
                weighed_at: $at->format(DATE_ATOM),
                weight_field: '',
                waist_field: '',
            )];
        }

        // Stable, so weigh-ins at the same moment keep their newest-first order.
        usort($rows, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(fn (array $row) => $row[1], $rows);
    }

    /** WT-020: every day with a count, newest first. */
    private static function steps(User $user, CarbonImmutable $now): StepsSection
    {
        $rows = Step::query()->where('user_id', $user->id)->orderByDesc('date')->get()
            ->map(function (Step $step): StepsRow {
                $date = $step->date->toDateTimeImmutable();

                return new StepsRow(
                    date: $date->format('Y-m-d'),
                    date_label: self::dateLabel($date),
                    value_label: TodayWords::steps($step->count),
                    source_label: StepCounts::sourceLabel($step->source),
                    steps: $step->count,
                );
            })
            ->values()
            ->all();

        return new StepsSection(
            title: 'Steps',
            default_date: StepCounts::defaultDate($now->toDateTimeImmutable())->format('Y-m-d'),
            rows: $rows,
        );
    }

    /** "Sat, 10 Oct". */
    private static function dateLabel(DateTimeImmutable $at): string
    {
        return $at->format('D, j M');
    }

    /** "8:35 AM". */
    private static function timeLabel(DateTimeImmutable $at): string
    {
        return ClockTime::at((int) $at->format('G'), (int) $at->format('i'))->shortText();
    }
}
