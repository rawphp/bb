<?php

namespace App\Capabilities\Reads\WeighIns;

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
use App\Domain\Placement\ClockTime;
use App\Domain\WeighIns\BodyMeasures;
use App\Domain\WeighIns\StepCounts;
use App\Domain\WeighIns\WeighInSchedule;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Support\Today\TodayWeight;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * The Weigh-ins screen (WT-001 to WT-021), every value ready to show in the user's units (CAP-015). Times and days
 * are in the app's time zone, as Today shows them.
 *
 * WT-004: the weigh-ins, missed days and step counts come a page of PAGE_DAYS calendar days at a time, newest first.
 * A page is the days [start, end): end is the `before` day, or tomorrow for the newest page. Each day lands on one
 * page. The trend, schedule and the rest are the whole set on every page.
 */
final class WeighInsPayload
{
    public const HELP_TITLE = 'Weighing in';

    /** WT-005. */
    public const HELP = 'Weigh yourself first thing in the morning, after the bathroom, in minimal clothing. Single readings bounce around; only the trend matters.';

    /** WT-004: the calendar days one read covers. */
    public const PAGE_DAYS = 90;

    /** @param  ?string  $before  a Y-m-d day: the page ends the day before it. Null for the newest page. */
    public static function build(User $user, CarbonImmutable $now, ?string $before = null): WeighInsShowOutput
    {
        $profile = Profile::query()->where('user_id', $user->id)->first();
        $weightUnit = $profile?->weight_unit === 'lb' ? 'lb' : 'kg';
        $waistUnit = BodyMeasures::waistUnit((string) ($profile->height_unit ?? 'cm'));

        $today = $now->utc()->startOfDay();
        $end = $before === null ? $today->addDay() : CarbonImmutable::createFromFormat('!Y-m-d', $before, 'UTC');
        $start = $end->subDays(self::PAGE_DAYS);

        $first = WeighIn::query()->where('user_id', $user->id)->min('weighed_at');
        $firstAt = $first === null ? null : CarbonImmutable::parse($first, 'UTC');
        $weighIns = WeighIn::query()->where('user_id', $user->id)
            ->where('weighed_at', '>=', $start)->where('weighed_at', '<', $end)
            ->orderByDesc('weighed_at')->orderByDesc('id')->get()->all();
        $olderSteps = Step::query()->where('user_id', $user->id)->where('date', '<', $start->format('Y-m-d'))->exists();
        $hasOlder = ($firstAt !== null && $firstAt->lt($start)) || $olderSteps;
        $entries = WeighInScheduleEntry::query()->where('user_id', $user->id)->orderBy('id')->get()->all();
        $weekdays = array_map(fn (WeighInScheduleEntry $entry) => array_map('intval', $entry->weekdays ?? []), $entries);
        $trendKg = TodayWeight::trendKg($user);

        return new WeighInsShowOutput(
            title: 'Weigh-ins',
            trend: new Line('Trend weight', $trendKg === null ? 'No weigh-ins yet' : BodyMeasures::weightLabel($trendKg, $weightUnit), 'neutral'),
            help: new WeighInHelp(self::HELP_TITLE, self::HELP),
            units: new WeighInUnits($weightUnit, $waistUnit),
            schedule: self::schedule($entries, $weekdays),
            rows: self::rows($weighIns, $entries, $weekdays, self::missedFrom($firstAt, $start), $end->min($today), $weightUnit, $waistUnit),
            missed: new Status('Missed', 'warning'),
            empty_message: $firstAt === null ? 'No weigh-ins yet. Tap + to log your first.' : null,
            steps: self::steps($user, $now, $start, $end),
            next_before: $hasOlder ? $start->format('Y-m-d') : null,
        );
    }

    /** Missed days start at the first weigh-in's day, and on this page no earlier than its start. Null before one. */
    private static function missedFrom(?CarbonImmutable $firstAt, CarbonImmutable $start): ?CarbonImmutable
    {
        return $firstAt === null ? null : $firstAt->startOfDay()->max($start);
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
     * WT-004: the page's weigh-ins and the missed scheduled days in [$from, $until), newest first.
     *
     * @param  list<WeighIn>  $weighIns  every weigh-in on the page
     * @param  list<WeighInScheduleEntry>  $entries
     * @param  list<list<int>>  $weekdays  each entry's
     * @return list<WeighInRow>
     */
    private static function rows(array $weighIns, array $entries, array $weekdays, ?CarbonImmutable $from, CarbonImmutable $until, string $weightUnit, string $waistUnit): array
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
        $missedDays = $from === null ? [] : WeighInSchedule::missedDaysBetween(
            array_keys($times),
            array_map(fn (WeighIn $w) => $w->weighed_at->toDateTimeImmutable(), $weighIns),
            $from->toDateTimeImmutable(),
            $until->toDateTimeImmutable(),
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

    /** WT-020: the page's days with a count, newest first. */
    private static function steps(User $user, CarbonImmutable $now, CarbonImmutable $start, CarbonImmutable $end): StepsSection
    {
        $rows = Step::query()->where('user_id', $user->id)
            ->where('date', '>=', $start->format('Y-m-d'))->where('date', '<', $end->format('Y-m-d'))
            ->orderByDesc('date')->get()
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
