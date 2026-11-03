<?php

namespace App\Capabilities\Reads\WeighIns;

use App\Capabilities\Reads\Today\TodayWords;
use App\Capabilities\Support\Line;
use App\Capabilities\Support\Status;
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
use App\Support\UserDay;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The Weigh-ins screen (WT-001 to WT-021), every value ready to show in the user's units (CAP-015). Times and days
 * are the user's (SET-012), as Today shows them: a reading is labelled and counted on its local day at its local clock
 * time, through UserDay. The days themselves are midnight UTC of their Y-m-d, as everywhere.
 *
 * WT-004: the weigh-ins, missed days and step counts come a page of PAGE_DAYS calendar days at a time, newest first.
 * A page is the user's days [start, end): end is the `before` day, or tomorrow for the newest page, which also holds
 * anything dated later. Each day lands on one page. The trend, schedule and the rest are the whole set on every page.
 */
final class WeighInsPayload
{
    public const HELP_TITLE = 'Weighing in';

    /** WT-005. */
    public const HELP = 'Weigh yourself first thing in the morning, after the bathroom, in minimal clothing. Single readings bounce around; only the trend matters.';

    /** WT-004: the calendar days one read covers. */
    public const PAGE_DAYS = 90;

    /** @param  ?string  $before  a Y-m-d day: the page ends the day before it. Null, or a day after today, for the newest page. */
    public static function build(User $user, CarbonImmutable $now, ?string $before = null): WeighInsShowOutput
    {
        $profile = Profile::query()->where('user_id', $user->id)->first();
        $weightUnit = $profile?->weight_unit === 'lb' ? 'lb' : 'kg';
        $waistUnit = BodyMeasures::waistUnit((string) ($profile->height_unit ?? 'cm'));

        $zone = UserDay::zone($user);
        $today = CarbonImmutable::instance(UserDay::dayOf($user, $now));
        $tomorrow = $today->addDay();
        $end = $before === null ? $tomorrow : CarbonImmutable::createFromFormat('!Y-m-d', $before, 'UTC')->min($tomorrow);
        $start = $end->subDays(self::PAGE_DAYS);
        // The newest page also holds anything dated after today (steps for a day ahead of the user's, a weigh-in typed ahead).
        $until = $end->eq($tomorrow) ? null : $end;
        // The stored instants the page's local days start at.
        $startAt = UserDay::bounds($user, $start)[0];
        $untilAt = $until === null ? null : UserDay::bounds($user, $until)[0];

        $first = WeighIn::query()->where('user_id', $user->id)->min('weighed_at');
        $firstDay = $first === null ? null : CarbonImmutable::instance(UserDay::dayOf($user, (string) $first));
        $weighIns = WeighIn::query()->where('user_id', $user->id)
            ->where('weighed_at', '>=', $startAt)->when($untilAt, fn ($query) => $query->where('weighed_at', '<', $untilAt))
            ->orderByDesc('weighed_at')->orderByDesc('id')->get()->all();
        $olderSteps = Step::query()->where('user_id', $user->id)->where('date', '<', $start->format('Y-m-d'))->exists();
        $hasOlder = ($firstDay !== null && $firstDay->lt($start)) || $olderSteps;
        $entries = WeighInScheduleEntry::query()->where('user_id', $user->id)->orderBy('id')->get()->all();
        $weekdays = array_map(fn (WeighInScheduleEntry $entry) => array_map('intval', $entry->weekdays ?? []), $entries);
        $trendKg = TodayWeight::trendKg($user);

        return new WeighInsShowOutput(
            title: 'Weigh-ins',
            trend: new Line('Trend weight', $trendKg === null ? 'No weigh-ins yet' : BodyMeasures::weightLabel($trendKg, $weightUnit), 'neutral'),
            help: new WeighInHelp(self::HELP_TITLE, self::HELP),
            units: new WeighInUnits($weightUnit, $waistUnit),
            schedule: self::schedule($entries, $weekdays),
            rows: self::rows($zone, $weighIns, $entries, $weekdays, $firstDay?->max($start), $end->min($today), $weightUnit, $waistUnit),
            missed: new Status('Missed', 'warning'),
            empty_message: $firstDay === null ? 'No weigh-ins yet. Tap + to log your first.' : null,
            steps: self::steps($user, $now, $start, $until),
            next_before: $hasOlder ? $start->format('Y-m-d') : null,
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
     * WT-004: the page's weigh-ins and the missed scheduled days in [$from, $until), newest first on the user's clock.
     * Missed days start at the first weigh-in's day, and on this page no earlier than its start: $from is null before one.
     *
     * @param  DateTimeZone  $zone  the user's
     * @param  list<WeighIn>  $weighIns  every weigh-in on the page
     * @param  list<WeighInScheduleEntry>  $entries
     * @param  list<list<int>>  $weekdays  each entry's
     * @return list<WeighInRow>
     */
    private static function rows(DateTimeZone $zone, array $weighIns, array $entries, array $weekdays, ?CarbonImmutable $from, CarbonImmutable $until, string $weightUnit, string $waistUnit): array
    {
        $rows = [];
        $clocks = [];
        foreach ($weighIns as $weighIn) {
            // The user's clock time, labelled UTC, for the labels, the missed-day scan and the sort.
            $at = $clocks[] = UserDay::clockIn($zone, $weighIn->weighed_at);
            $waist = $weighIn->waist_cm === null ? null : (float) $weighIn->waist_cm;
            $rows[] = [$at, new WeighInRow(
                id: (string) $weighIn->id,
                is_missed: false,
                date_label: self::dateLabel($at),
                time_label: self::timeLabel($at),
                weight_label: BodyMeasures::weightLabel((float) $weighIn->weight_kg, $weightUnit),
                waist_label: $waist === null ? '' : BodyMeasures::waistLabel($waist, $waistUnit),
                weighed_at: $weighIn->weighed_at->toDateTimeImmutable()->setTimezone($zone)->format(DATE_ATOM),
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
            $clocks,
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
                // That local day at its reminder time, with the zone's offset on that day.
                weighed_at: (new DateTimeImmutable($at->format('Y-m-d H:i:s'), $zone))->format(DATE_ATOM),
                weight_field: '',
                waist_field: '',
            )];
        }

        // Stable, so weigh-ins at the same moment keep their newest-first order.
        usort($rows, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(fn (array $row) => $row[1], $rows);
    }

    /** WT-020: the page's days with a count, newest first. */
    private static function steps(User $user, CarbonImmutable $now, CarbonImmutable $start, ?CarbonImmutable $until): StepsSection
    {
        $rows = Step::query()->where('user_id', $user->id)
            ->where('date', '>=', $start->format('Y-m-d'))->when($until, fn ($query) => $query->where('date', '<', $until->format('Y-m-d')))
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
            default_date: StepCounts::defaultDate(UserDay::clockOf($user, $now))->format('Y-m-d'),
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
