<?php

namespace Tests\Unit\Capabilities\Reads\Progress;

use App\Capabilities\Reads\Progress\ProgressPayload;
use App\Domain\Progress\ProgressScope;
use App\Domain\Progression\WorkingSet;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\OffPlanEntry;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Progress\ProgressSnapshot;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;
use stdClass;

/**
 * rp-clone's ProgressModelTests scenario, as unsaved models so the Progress payloads run without a database.
 *
 * - Today is Saturday 2026-10-10, the plan's review day. The plan starts Tuesday 2026-08-04.
 * - Maintenance runs 2026-08-04 to 2026-09-01 and Fat loss (-0.5% a week) from 2026-09-01 to 2026-10-27.
 * - Base (4 weeks from 2026-08-04, in Maintenance), Cut A (4 weeks from 2026-09-01) and Cut B (6 weeks from
 *   2026-09-29), both in Fat loss; and a draft with no dates.
 * - The step plan allows 6000 to 8000 on Saturday (ISO 6) and 7000 to 9000 on Tuesday.
 * - 40 weigh-ins of 80 kg, one a day at 07:00 from 2026-09-01.
 * - Cut A's weeks 1 and 2 hold the logged sets of exercises 12 (Bench Press) and 20 (Curl); a session in no mesocycle,
 *   a set not logged and a set with no exercise must not count.
 */
final class ProgressWorld
{
    public const TODAY = '2026-10-10';

    public const HOLD = 'aaaaaaaa-0000-4000-8000-000000000001';

    public const CUT = 'aaaaaaaa-0000-4000-8000-000000000002';

    public const BASE = 'bbbbbbbb-0000-4000-8000-000000000001';

    public const CUT_A = 'bbbbbbbb-0000-4000-8000-000000000002';

    public const CUT_B = 'bbbbbbbb-0000-4000-8000-000000000003';

    public const DRAFT = 'cccccccc-0000-4000-8000-000000000009';

    /**
     * @param  list<WeighIn>|null  $weighIns  40 days of 80 kg when null
     * @param  list<string>  $targetDays  days with a $targetKcal, 150 g protein target
     * @param  array<string, array{0: float, 1: float}>  $consumed  kcal and protein eaten by day (as off-plan entries)
     * @param  list<PlanWeek>  $planWeeks
     * @param  array<string, mixed>  $profile  replaces profile attributes
     * @param  list<Phase>|null  $phases
     * @param  list<Mesocycle>|null  $mesocycles
     * @param  list<WorkoutSession>|null  $sessions
     * @param  list<WorkoutSet>|null  $sets
     * @param  list<stdClass>|null  $rows  the loader rows themselves (see row()), in place of $sessions and $sets
     * @param  array<int, string>|null  $lastUsed  exercise id => last used
     */
    public static function snapshot(
        string $today = self::TODAY,
        ?array $weighIns = null,
        string $planStart = '2026-08-04',
        int $reviewWeekday = 6,
        array $targetDays = [],
        array $consumed = [],
        array $planWeeks = [],
        array $profile = [],
        ?int $steps = null,
        ?array $phases = null,
        ?array $mesocycles = null,
        ?array $sessions = null,
        ?array $sets = null,
        ?array $rows = null,
        ?array $lastUsed = null,
        bool $withPlan = true,
        float $targetKcal = 2000,
    ): ProgressSnapshot {
        $targets = array_map(fn (string $day) => (new DayTarget)->forceFill([
            'id' => "dt-{$day}", 'user_id' => 1, 'date' => $day, 'day_type' => 'hard', 'kcal' => $targetKcal, 'protein_g' => 150,
            'fat_g' => 60, 'carbs_g' => 200, 'meals' => [],
        ]), $targetDays);
        $offPlan = [];
        foreach ($consumed as $day => [$kcal, $protein]) {
            $offPlan[] = (new OffPlanEntry)->forceFill([
                'id' => "op-{$day}", 'user_id' => 1, 'date' => $day, 'name' => null, 'time' => null,
                'kcal' => $kcal, 'protein_g' => $protein, 'fat_g' => 0, 'carbs_g' => 0,
            ]);
        }
        $profileRow = (new Profile)->forceFill(array_replace([
            'user_id' => 1, 'experience' => 'intermediate', 'weight_unit' => 'kg', 'height_unit' => 'cm',
            'step_plan' => [['weekday' => 6, 'min' => 6000, 'max' => 8000], ['weekday' => 2, 'min' => 7000, 'max' => 9000]],
        ], $profile));
        $day = TrainSnapshot::day($today);
        $train = new TrainSnapshot(
            today: $day,
            plan: $withPlan ? (new Plan)->forceFill(['id' => 1, 'user_id' => 1, 'start_date' => $planStart, 'review_weekday' => $reviewWeekday]) : null,
            profile: $profileRow,
            mesocycles: new Collection($mesocycles ?? self::mesocycles()),
            phases: new Collection($phases ?? self::phases()),
            sessions: new Collection,
            sets: new Collection,
            feedback: new Collection,
            planWeeks: new Collection($planWeeks),
            dayTargets: new Collection,
            exercises: new Collection,
        );
        $week = new TodaySnapshot(
            train: $train,
            date: $day,
            lastTarget: $targetDays === [] ? null : TrainSnapshot::day(max($targetDays)),
            dayTargets: new Collection($targets),
            meals: new Collection,
            offPlan: new Collection($offPlan),
            otherActivities: new Collection,
            weighIns: new Collection,
            weighInSchedule: new Collection,
            steps: null,
        );

        return new ProgressSnapshot(
            week: $week,
            weighIns: new Collection($weighIns ?? self::weighIns()),
            steps: $steps,
            sets: $rows === null ? self::rows($sessions ?? self::sessions(), $sets ?? self::sets()) : new Collection($rows),
            exercises: new Collection(self::exercises()),
            lastUsed: $lastUsed ?? [12 => '2026-10-08 07:30:00', 20 => '2026-10-05 07:30:00', 25 => '2026-10-09 07:30:00'],
        );
    }

    /** @return array<string, mixed> the payload as the client gets it */
    public static function payload(ProgressSnapshot $snapshot, ProgressScope $scope = ProgressScope::CurrentPhase): array
    {
        return ProgressPayload::build($snapshot, $scope)->toArray();
    }

    /** @return list<Phase> */
    public static function phases(): array
    {
        return [
            self::phase(self::HOLD, 'maintenance', '2026-08-04', '2026-09-01', 0),
            self::phase(self::CUT, 'fatLoss', '2026-09-01', '2026-10-27', -0.5),
        ];
    }

    public static function phase(string $id, string $type, string $start, ?string $end, float $rate): Phase
    {
        return (new Phase)->forceFill([
            'id' => $id, 'user_id' => 1, 'type' => $type, 'start_date' => $start, 'end_date' => $end,
            'target_rate_percent_per_week' => $rate,
        ]);
    }

    /** @return list<Mesocycle> */
    public static function mesocycles(): array
    {
        return [
            self::mesocycle(self::BASE, 'Base', self::HOLD, '2026-08-04', 4),
            self::mesocycle(self::CUT_A, 'Cut A', self::CUT, '2026-09-01', 4),
            self::mesocycle(self::CUT_B, 'Cut B', self::CUT, '2026-09-29', 6),
            (new Mesocycle)->forceFill([
                'id' => self::DRAFT, 'user_id' => 1, 'phase_id' => null, 'name' => null, 'weeks' => null, 'start_date' => null,
                'ended_on' => null, 'is_draft' => true,
            ]),
        ];
    }

    public static function mesocycle(string $id, ?string $name, ?string $phase, string $start, int $weeks, ?string $endedOn = null): Mesocycle
    {
        return (new Mesocycle)->forceFill([
            'id' => $id, 'user_id' => 1, 'phase_id' => $phase, 'name' => $name, 'weeks' => $weeks, 'start_date' => $start,
            'ended_on' => $endedOn, 'is_draft' => false, 'days' => [],
        ]);
    }

    /** One weigh-in a day at 07:00 from a day, oldest first. @return list<WeighIn> */
    public static function weighIns(string $from = '2026-09-01', int $days = 40, float $kg = 80, ?float $waistCm = null): array
    {
        $rows = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $rows[] = self::weighIn(TrainSnapshot::day($from)->modify("+{$offset} days")->format('Y-m-d').' 07:00:00', $kg, $waistCm);
        }

        return $rows;
    }

    public static function weighIn(string $at, float $kg, ?float $waistCm = null): WeighIn
    {
        return (new WeighIn)->forceFill(['id' => "w-{$at}", 'user_id' => 1, 'weight_kg' => $kg, 'waist_cm' => $waistCm, 'weighed_at' => $at]);
    }

    public static function planWeek(string $start, bool $away = false, ?string $awayTraining = null, ?string $reviewedAt = null): PlanWeek
    {
        return (new PlanWeek)->forceFill([
            'id' => "pw-{$start}", 'user_id' => 1, 'start_date' => $start, 'is_deload' => false, 'is_diet_break' => false,
            'diet_break_off' => false, 'is_away' => $away, 'away_training' => $awayTraining, 'reviewed_at' => $reviewedAt,
        ]);
    }

    /**
     * The rows the loader reads: the working sets (WO-025) of live sessions in a mesocycle that name an exercise,
     * counted per mesocycle, week and exercise, as its grouped SQL does.
     *
     * @param  list<WorkoutSession>  $sessions
     * @param  list<WorkoutSet>  $sets
     */
    private static function rows(array $sessions, array $sets): Collection
    {
        $byId = collect($sessions)->keyBy(fn (WorkoutSession $session) => (string) $session->id);
        $counts = [];
        foreach ($sets as $set) {
            $session = $byId->get((string) $set->workout_session_id);
            if ($session === null || $session->mesocycle_id === null || $set->exercise_id === null
                || ! WorkingSet::counts((bool) $set->is_logged, (bool) $set->is_skipped)) {
                continue;
            }
            $key = "{$session->mesocycle_id}|{$session->week}|{$set->exercise_id}";
            $counts[$key] ??= self::row((string) $session->mesocycle_id, (int) $session->week, (int) $set->exercise_id, 0);
            $counts[$key]->sets++;
        }

        return new Collection(array_values($counts));
    }

    /** One loader row: `$sets` working sets of an exercise in a mesocycle week. */
    public static function row(string $mesocycle, int $week, int $exercise, int $sets): stdClass
    {
        return (object) ['mesocycle_id' => $mesocycle, 'week' => $week, 'exercise_id' => $exercise, 'sets' => $sets];
    }

    /** @return list<WorkoutSession> */
    public static function sessions(): array
    {
        return [self::session('s-a', self::CUT_A, 1), self::session('s-b', self::CUT_A, 2), self::session('s-c', null, 1)];
    }

    public static function session(string $id, ?string $mesocycle, int $week): WorkoutSession
    {
        return (new WorkoutSession)->forceFill(['id' => $id, 'user_id' => 1, 'mesocycle_id' => $mesocycle, 'week' => $week]);
    }

    /** @return list<WorkoutSet> */
    public static function sets(): array
    {
        return [
            self::set('1', 's-a', 12), self::set('2', 's-a', 12), self::set('3', 's-a', 12),
            self::set('4', 's-b', 12), self::set('5', 's-b', 12), self::set('6', 's-b', 12, logged: false),
            self::set('7', 's-a', 20), self::set('8', 's-c', 12),
        ];
    }

    public static function set(string $id, string $session, ?int $exercise, bool $logged = true, bool $skipped = false): WorkoutSet
    {
        return (new WorkoutSet)->forceFill([
            'id' => $id, 'user_id' => 1, 'workout_session_id' => $session, 'exercise_id' => $exercise, 'position' => 1,
            'is_logged' => $logged, 'is_skipped' => $skipped,
        ]);
    }

    /** @return list<Exercise> */
    public static function exercises(): array
    {
        return array_map(fn (array $row) => (new Exercise)->forceFill(['id' => $row[0], 'name' => $row[1], 'muscle_group' => $row[2]]), [
            [12, 'Bench Press', 'chest'], [20, 'Curl', 'biceps'], [25, 'Barbell Row', 'back'], [30, 'Back Squat', 'quads'],
        ]);
    }
}
