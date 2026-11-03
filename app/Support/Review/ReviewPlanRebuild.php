<?php

namespace App\Support\Review;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan as DomainPlan;
use App\Domain\Model\Profile as DomainProfile;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Retarget\OtherActivity as DomainOtherActivity;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekSchedule;
use App\Domain\Weight\CurrentWeight;
use App\Models\Mesocycle;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;

/**
 * The parts of rp-clone's PlanRebuild the weekly review reads: the stored plan, profile, schedule, away week, Other
 * activities and covering mesocycle as the domain takes them. Review-only; Settings rebuilds keep their own.
 */
final class ReviewPlanRebuild
{
    /**
     * The plan the stored rows describe, with its review day as a Domain weekday (1 = Sunday).
     *
     * @param  Collection<int, Phase>  $phases  sorted by start
     */
    public static function plan(Plan $record, Collection $phases): DomainPlan
    {
        return new DomainPlan(
            id: (string) $record->id,
            start: TrainSnapshot::day($record->start_date),
            reviewWeekday: PlanWeekDays::calendarWeekdayFromIso($record->review_weekday),
            phases: $phases->map(fn (Phase $phase) => PlanSnapshot::domainPhase($phase))->values()->all(),
            reviewDayChanges: $record->domainReviewDayChanges(),
        );
    }

    /** The profile as the rules read it, at the trend weight when there is one (WT-011). */
    public static function domainProfile(Profile $record, ?float $trendKg): DomainProfile
    {
        return new DomainProfile(
            name: (string) $record->name,
            sex: Sex::from((string) $record->sex),
            birthDate: TrainSnapshot::day($record->birth_date),
            heightCm: (float) $record->height_cm,
            weightKg: CurrentWeight::kg($trendKg, (float) $record->weight_kg),
            weightUnit: WeightUnit::tryFrom((string) $record->weight_unit) ?? WeightUnit::Kg,
            heightUnit: HeightUnit::tryFrom((string) $record->height_unit) ?? HeightUnit::Cm,
            // A training-only profile has no body fat or GLP-1 answers; nothing it builds reads them (SAFE-003).
            bodyFatBucket: BodyFatBucket::tryFrom((string) $record->body_fat_bucket) ?? BodyFatBucket::Low,
            usesGLP1: (bool) $record->uses_glp1,
            experience: ExperienceLevel::tryFrom((string) $record->experience) ?? ExperienceLevel::Intermediate,
        );
    }

    /** The profile's schedule. A training-only profile has no nutrition (SAFE-003). */
    public static function schedule(Profile $profile): WeekSchedule
    {
        $trainingDays = array_map(fn (array $day) => new TrainingDay(
            PlanWeekDays::calendarWeekdayFromIso((int) $day['weekday']),
            new WorkoutTime(self::clock($day['start'] ?? null), (int) ($day['minutes'] ?? 60)),
        ), array_values(TodaySnapshot::json($profile->training_days)));
        if ($profile->training_only) {
            return new WeekSchedule($trainingDays, null);
        }
        $window = TodaySnapshot::json($profile->eating_window);
        $busy = [];
        // Busy periods belong to weekdays, so each day is placed with its own.
        foreach (range(1, 7) as $iso) {
            $busy[$iso] = [];
            foreach (TodaySnapshot::json($profile->busy_periods) as $period) {
                if (in_array($iso, $period['weekdays'] ?? range(1, 7), false)) {
                    $busy[$iso][] = new ClockRange(self::clock($period['start'] ?? null), self::clock($period['end'] ?? null));
                }
            }
        }

        return new WeekSchedule($trainingDays, new NutritionSchedule(
            steps: array_map(
                fn (array $day) => new StepPlanDay((int) $day['weekday'], (int) $day['min'], (int) $day['max']),
                array_values(TodaySnapshot::json($profile->step_plan)),
            ),
            eatingWindow: new ClockRange(self::clock($window['start'] ?? null), self::clock($window['end'] ?? null)),
            mealsPerDay: (int) $profile->meal_count,
            busyByWeekday: $busy,
        ));
    }

    /** SCH-060: the choices of a week marked away. */
    public static function away(?PlanWeek $row): ?AwayWeek
    {
        if ($row === null || ! $row->is_away) {
            return null;
        }

        return new AwayWeek(
            Training::tryFrom((string) $row->away_training) === Training::Deload ? Training::Deload : Training::Pause,
            Calories::tryFrom((string) $row->away_calories) === Calories::RestDays ? Calories::RestDays : Calories::Maintenance,
        );
    }

    /**
     * SCH-038: the stored Other activities by day (the first of a day's, if there were several).
     *
     * @param  Collection<int, OtherActivity>  $records
     * @return array<string, DomainOtherActivity> keyed by "Y-m-d"
     */
    public static function activitiesByDay(Collection $records): array
    {
        $byDay = [];
        foreach ($records as $record) {
            $byDay[TrainSnapshot::day($record->date)->format('Y-m-d')] ??= new DomainOtherActivity(
                (string) $record->name, (int) $record->minutes, DayType::from((string) $record->intensity),
            );
        }

        return $byDay;
    }

    /**
     * PLAN-002: the mesocycle covering `$week`, or one with no weeks (no workouts), with the plan week it started on,
     * the weeks an away week pauses (SCH-060) and its stored row. Its phase is the one that owns it, else `$fallback`.
     *
     * @return array{mesocycle: DomainMesocycle, startWeek: int, pausedWeeks: list<int>, record: ?Mesocycle}
     */
    public static function coveringMesocycle(int $week, TrainSnapshot $train, PlanWeekGrid $grid, PhaseType $fallback): array
    {
        $paused = array_values(array_unique($train->planWeeks
            ->filter(fn (PlanWeek $row) => $row->is_away && $row->away_training === Training::Pause->value)
            ->map(fn (PlanWeek $row) => $grid->weekIndexContaining(TrainSnapshot::day($row->start_date)))
            ->filter(fn (?int $index) => $index !== null)
            ->values()->all()));
        $records = $train->mesocycles->values();
        $covering = PlanRules::coveringMesocycle(
            $week,
            $records->map(fn (Mesocycle $m) => $m->isSaved() && $m->dayRows() !== []
                ? new DatedMesocycle(TrainSnapshot::day($m->start_date), (int) $m->weeks)
                : null)->all(),
            $grid,
            $paused,
        );
        if ($covering === null) {
            return [
                'mesocycle' => new DomainMesocycle(id: '', phase: $fallback, weeks: 0, sessions: [], musclePriorities: []),
                'startWeek' => 0,
                'pausedWeeks' => $paused,
                'record' => null,
            ];
        }
        $record = $records[$covering->index];
        $domain = $train->domain($record);

        return [
            'mesocycle' => new DomainMesocycle(
                id: $domain->id,
                phase: $train->phaseType($record) ?? $fallback,
                weeks: $covering->placed->weeks,
                sessions: $domain->sessions,
                musclePriorities: $domain->musclePriorities,
                isResensitization: $domain->isResensitization,
                earlierWeeks: $domain->earlierWeeks,
                laterWeeks: $domain->laterWeeks,
                // MESO-043: an ended mesocycle plans no session after its last day.
                lastDay: $record->ended_on === null ? null : $train->calendar($record)->lastDay(),
            ),
            'startWeek' => $covering->placed->startWeek,
            'pausedWeeks' => $paused,
            'record' => $record,
        ];
    }

    /** "HH:MM" as a clock time; midnight when it cannot be read. */
    private static function clock(mixed $text): ClockTime
    {
        return ClockTime::fromHhmm((string) $text) ?? new ClockTime(0);
    }
}
