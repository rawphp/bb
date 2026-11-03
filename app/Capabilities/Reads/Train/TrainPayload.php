<?php

namespace App\Capabilities\Reads\Train;

use App\Capabilities\Mesocycles\Data\TrainCreateRoute;
use App\Capabilities\Mesocycles\Data\TrainCurrentMesocycle;
use App\Capabilities\Mesocycles\Data\TrainMesocycleDraft;
use App\Capabilities\Mesocycles\Data\TrainMesocycleRow;
use App\Capabilities\Mesocycles\Data\TrainMesocycleSection;
use App\Capabilities\Mesocycles\Data\TrainNextMesocycle;
use App\Capabilities\Mesocycles\Data\TrainShowOutput;
use App\Capabilities\Mesocycles\Data\TrainTodaySession;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Model\PhaseType;
use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;
use App\Support\Mesocycles\TrainSnapshot;

/**
 * The Train tab (NAV-002, MESO-001, MESO-002, MESO-007, MESO-060, MESO-064, MESO-065), from a {@see TrainSnapshot}.
 */
final class TrainPayload
{
    public const DRAFT_TEXT = 'Saved as a draft. Tap to continue building.';

    public const REPLACE_WARNING = 'You have an unfinished mesocycle. Starting a new one replaces it.';

    public const ACTIVE_REST = 'Training has gone flat. An active rest, the deload week plus a week off, is recommended.';

    public const ACTIVE_REST_TAKEN = 'Active rest taken: the deload week plus a week off before the next mesocycle.';

    private const SECTIONS = ['current' => 'Current', 'planned' => 'Planned', 'past' => 'Past'];

    public static function build(TrainSnapshot $snapshot): TrainShowOutput
    {
        $draft = $snapshot->draft();

        return new TrainShowOutput(
            todays_session: self::todaysSession($snapshot),
            current: self::current($snapshot),
            draft: $draft === null ? null : new TrainMesocycleDraft(
                id: (string) $draft->id,
                name: $draft->name ?? 'New mesocycle',
                text: self::DRAFT_TEXT,
                replace_warning: self::REPLACE_WARNING,
            ),
            sections: self::sections($snapshot),
            create_routes: self::routes($snapshot),
        );
    }

    /**
     * MESO-065: "Priorities reset for Fat loss. …" when a copy of `$source` starting on `$start` takes the new
     * phase's preset.
     */
    public static function priorityNotice(TrainSnapshot $snapshot, Mesocycle $source, \DateTimeImmutable $start): ?string
    {
        $phase = $snapshot->priorityReset($source, $start);

        return $phase === null ? null : "Priorities reset for {$phase->type->title()}. Your old priorities can be kept.";
    }

    /** NAV-002: today's planned session of the running mesocycle, done or not; none on a rest day. */
    private static function todaysSession(TrainSnapshot $snapshot): ?TrainTodaySession
    {
        $mesocycle = $snapshot->current();
        if ($mesocycle === null) {
            return null;
        }
        $calendar = $snapshot->calendar($mesocycle);
        if (! $calendar->isRunning()) {
            return null;
        }

        $week = $calendar->currentWeek();
        $sessions = $snapshot->domain($mesocycle)->sessionsInWeek($week - 1);
        $weekdays = $snapshot->weekdays($mesocycle);
        $dates = MesocycleCalendar::dayDates(
            $calendar->planWeek($week - 1),
            array_map(fn ($session) => $weekdays[$session->id] ?? null, $sessions),
            $snapshot->trainingWeekdays(),
            $snapshot->reviewIsoWeekday($calendar->grid),
        );

        foreach ($dates as $index => $date) {
            if ($date == $snapshot->today) {
                return new TrainTodaySession(
                    title: sprintf('Week %d · Day %d · %s', $week, $index + 1, $sessions[$index]->name),
                    mesocycle_id: (string) $mesocycle->id,
                    week: $week,
                    day: $index + 1,
                );
            }
        }

        return null;
    }

    /** The running mesocycle's card; once it is over, kept while the next-mesocycle prompt is up (MESO-060). */
    private static function current(TrainSnapshot $snapshot): ?TrainCurrentMesocycle
    {
        $mesocycle = $snapshot->current();
        $next = $snapshot->nextMesocycle();
        if ($mesocycle === null || (! $snapshot->calendar($mesocycle)->isRunning() && $next === null)) {
            return null;
        }
        $calendar = $snapshot->calendar($mesocycle);

        return new TrainCurrentMesocycle(
            id: (string) $mesocycle->id,
            name: $mesocycle->name ?? 'Mesocycle',
            week_text: self::weekText($calendar),
            phase_title: TrainWords::phase($snapshot->phaseType($mesocycle)),
            dates_text: self::dates($calendar),
            next_mesocycle: $next === null ? null : new TrainNextMesocycle(
                starts_text: TrainWords::starts($next->start),
                start_date: $next->start->format('Y-m-d'),
                copy_source_id: (string) $mesocycle->id,
                template_phase: self::templatePhase($snapshot->phaseCovering($next->start)?->type),
                priority_notice: self::priorityNotice($snapshot, $mesocycle, $next->start),
                active_rest: match (true) {
                    $next->tookActiveRest => new Status(self::ACTIVE_REST_TAKEN, Tone::Good->value),
                    $next->recommendsActiveRest => new Status(self::ACTIVE_REST, Tone::Info->value),
                    default => null,
                },
            ),
        );
    }

    /**
     * MESO-001: current, then planned soonest first, then past most recent first. Empty groups are left out.
     *
     * @return list<TrainMesocycleSection>
     */
    private static function sections(TrainSnapshot $snapshot): array
    {
        $grouped = ['current' => [], 'planned' => [], 'past' => []];
        foreach ($snapshot->saved()->sortBy(fn (Mesocycle $m) => $m->start_date) as $mesocycle) {
            $calendar = $snapshot->calendar($mesocycle);
            $endedBeforeStart = $calendar->endedOn !== null && $calendar->endedOn <= $calendar->start;
            $status = match (true) {
                $snapshot->today < $calendar->start => 'planned',
                $snapshot->today <= max($calendar->lastDay(), $calendar->start) && ! $endedBeforeStart => 'current',
                default => 'past',
            };
            $grouped[$status][] = new TrainMesocycleRow(
                id: (string) $mesocycle->id,
                name: $mesocycle->name ?? '',
                dates_text: self::dates($calendar),
                summary_text: TrainWords::weeks($calendar->weeks).' · '.TrainWords::phase($snapshot->phaseType($mesocycle)),
            );
        }
        $grouped['past'] = array_reverse($grouped['past']);

        $sections = [];
        foreach ($grouped as $status => $rows) {
            if ($rows !== []) {
                $sections[] = new TrainMesocycleSection($status, self::SECTIONS[$status], $rows);
            }
        }

        return $sections;
    }

    /**
     * MESO-002: the four routes, in the + menu's order. Copy needs a saved mesocycle; it names the one it copies and
     * the MESO-065 notice.
     *
     * @return list<TrainCreateRoute>
     */
    private static function routes(TrainSnapshot $snapshot): array
    {
        $source = $snapshot->copySource();
        $routes = [];
        foreach (MesocycleRoute::cases() as $route) {
            $copy = $route === MesocycleRoute::CopyPrevious ? $source : null;
            $routes[] = new TrainCreateRoute(
                route: $route->value,
                title: $route->title(),
                available: $route !== MesocycleRoute::CopyPrevious || $source !== null,
                copy_source_id: $copy?->id,
                priority_notice: $copy === null ? null : self::priorityNotice($snapshot, $copy, $snapshot->copyStart($copy)),
            );
        }

        return $routes;
    }

    /** "Week 3 of 5", "Week 5 of 5 · Deload"; once over, "Ended 6 Oct" or "Finished 18 Oct" (MESO-043). */
    private static function weekText(MesocycleCalendar $calendar): string
    {
        if (! $calendar->isRunning()) {
            return $calendar->endedOn !== null
                ? 'Ended '.TrainWords::day($calendar->endedOn)
                : 'Finished '.TrainWords::day($calendar->lastDay());
        }
        $week = $calendar->currentWeek();

        return "Week {$week} of {$calendar->weeks}".($week === $calendar->weeks ? ' · Deload' : '');
    }

    /** MESO-043: from the start to the last day, which is never before the start. */
    private static function dates(MesocycleCalendar $calendar): string
    {
        return TrainWords::dates($calendar->start, max($calendar->lastDay(), $calendar->start));
    }

    /** MESO-060: the template library's phase filter for the new week 1. */
    private static function templatePhase(?PhaseType $type): string
    {
        return match ($type) {
            PhaseType::FatLoss, PhaseType::MiniCut => 'cut',
            PhaseType::MuscleGain => 'gain',
            PhaseType::Maintenance => 'maintain',
            null => 'all',
        };
    }
}
