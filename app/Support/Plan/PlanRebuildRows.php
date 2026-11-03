<?php

namespace App\Support\Plan;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile as DomainProfile;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Retarget\OtherActivity as DomainOtherActivity;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekSchedule;
use App\Models\OtherActivity;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;

/** SET-004: the stored profile, schedule, away week, Other activities and covering mesocycle as the week build takes them. */
final class PlanRebuildRows
{
    /** The profile as the rules read it, at `$weightKg` (the trend weight, WT-011). */
    public static function profile(Profile $row, float $weightKg): DomainProfile
    {
        return new DomainProfile(
            name: (string) $row->name,
            sex: Sex::from((string) $row->sex),
            birthDate: TrainSnapshot::day($row->birth_date),
            heightCm: (float) $row->height_cm,
            weightKg: $weightKg,
            weightUnit: WeightUnit::tryFrom((string) $row->weight_unit) ?? WeightUnit::Kg,
            heightUnit: HeightUnit::tryFrom((string) $row->height_unit) ?? HeightUnit::Cm,
            // A training-only profile has no body fat or GLP-1 answers; nothing it builds reads them (SAFE-003).
            bodyFatBucket: BodyFatBucket::tryFrom((string) $row->body_fat_bucket) ?? BodyFatBucket::Low,
            usesGLP1: (bool) $row->uses_glp1,
            experience: ExperienceLevel::tryFrom((string) $row->experience) ?? ExperienceLevel::Intermediate,
        );
    }

    /** The profile's schedule. A training-only profile has no nutrition (SAFE-003). */
    public static function schedule(Profile $row): WeekSchedule
    {
        $trainingDays = array_map(fn (array $day) => new TrainingDay(
            PlanWeekDays::calendarWeekdayFromIso((int) $day['weekday']),
            new WorkoutTime(self::clock($day['start'] ?? null), (int) ($day['minutes'] ?? 60)),
        ), array_values(TodaySnapshot::json($row->training_days)));
        if ($row->training_only) {
            return new WeekSchedule($trainingDays, null);
        }

        $window = TodaySnapshot::json($row->eating_window);
        // Busy periods belong to weekdays, so each day is placed with its own. One with no weekdays applies on all seven.
        $busy = [];
        foreach (range(1, 7) as $iso) {
            $busy[$iso] = [];
            foreach (TodaySnapshot::json($row->busy_periods) as $period) {
                if (in_array($iso, $period['weekdays'] ?? range(1, 7), false)) {
                    $busy[$iso][] = new ClockRange(self::clock($period['start'] ?? null), self::clock($period['end'] ?? null));
                }
            }
        }

        return new WeekSchedule($trainingDays, new NutritionSchedule(
            steps: array_map(
                fn (array $day) => new StepPlanDay((int) $day['weekday'], (int) $day['min'], (int) $day['max']),
                array_values(TodaySnapshot::json($row->step_plan)),
            ),
            eatingWindow: new ClockRange(self::clock($window['start'] ?? null), self::clock($window['end'] ?? null)),
            mealsPerDay: (int) $row->meal_count,
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
     * @param  Collection<int, OtherActivity>  $rows
     * @return array<string, DomainOtherActivity> keyed by Y-m-d
     */
    public static function activitiesByDay(Collection $rows): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[TrainSnapshot::day($row->date)->format('Y-m-d')] ??= new DomainOtherActivity(
                (string) $row->name, (int) $row->minutes, DayType::from((string) $row->intensity),
            );
        }

        return $byDay;
    }

    /**
     * PLAN-002: the mesocycle covering `$week`, or one of the phase's type with no workouts, and the plan week it
     * starts on. `$snapshot` holds only saved mesocycles with days, so a draft, or one with no days, never covers a
     * week. It runs its stored weeks, not the weeks it is placed for: an ended one keeps its deload where it was.
     *
     * @return array{mesocycle: DomainMesocycle, startWeek: int}
     */
    public static function mesocycle(PlanSnapshot $snapshot, TrainSnapshot $train, int $week, PhaseType $fallback): array
    {
        $covering = $snapshot->mesocycleCoveringWeek($week);
        if ($covering === null) {
            return [
                'mesocycle' => new DomainMesocycle(id: '', phase: $fallback, weeks: 0, sessions: [], musclePriorities: []),
                'startWeek' => 0,
            ];
        }
        $record = $covering->mesocycle;
        $domain = $train->domain($record);

        return [
            'mesocycle' => new DomainMesocycle(
                id: $domain->id,
                phase: $train->phaseType($record) ?? $fallback,
                weeks: (int) $record->weeks,
                sessions: $domain->sessions,
                musclePriorities: $domain->musclePriorities,
                isResensitization: $domain->isResensitization,
                earlierWeeks: $domain->earlierWeeks,
                laterWeeks: $domain->laterWeeks,
                // MESO-043: an ended mesocycle plans no session after its last day.
                lastDay: $record->ended_on === null ? null : $snapshot->calendar($record)->lastDay(),
            ),
            'startWeek' => $covering->placed->startWeek,
        ];
    }

    /** "HH:MM" as a clock time; midnight when it cannot be read. */
    private static function clock(mixed $text): ClockTime
    {
        return ClockTime::fromHhmm((string) $text) ?? new ClockTime(0);
    }
}
