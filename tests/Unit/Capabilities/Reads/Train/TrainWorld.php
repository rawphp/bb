<?php

namespace Tests\Unit\Capabilities\Reads\Train;

use App\Capabilities\Reads\Train\TrainSnapshot;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

/**
 * rp-clone's MesocycleViewFixtures as unsaved models, so the Train payloads run without a database.
 *
 * - The plan starts Monday 2026-09-14; its weeks start on Monday. The user trains Monday and Thursday.
 * - "Meso 2" is 5 weeks from that day under Muscle gain, week 5 the deload. Day 1 "Upper A" (Monday) is Bench
 *   Press 3, Barbell Row 3, Dumbbell Curl 2; Day 2 "Lower A" (Thursday) is Back Squat 3, Barbell Shrug 2.
 * - Logged: week 2 day 1 (bench 3, row 3, curl 2, plus a skipped and an unlogged bench set) and week 4 day 1
 *   (bench 3, curl 2).
 * - Day targets: 2400 and 2200 kcal on 21 and 22 September, 2000 on 5 October.
 * - Plan weeks: from 28 September a diet break, from 12 October a flagged deload.
 */
final class TrainWorld
{
    public const MESO = '01JB6Q3V8T2K9WZ4M5N7P1R3S5';

    public const GAIN = '0195a000-0000-7000-8000-000000000001';

    public const CUT = '0195a000-0000-7000-8000-000000000002';

    public const BENCH = 12;

    public const ROW = 13;

    public const CURL = 14;

    public const SQUAT = 15;

    public const SHRUG = 16;

    /**
     * @param  list<Mesocycle>|null  $mesocycles  Meso 2 when null
     * @param  list<Phase>|null  $phases  Muscle gain over the whole autumn when null
     * @param  list<WorkoutSession>|null  $sessions
     * @param  list<WorkoutSet>|null  $sets
     * @param  list<MuscleFeedback>  $feedback
     * @param  list<PlanWeek>|null  $planWeeks
     * @param  list<DayTarget>|null  $dayTargets
     * @param  list<int>  $trainingDays  ISO weekdays
     */
    public static function snapshot(
        string $today = '2026-10-06',
        ?array $mesocycles = null,
        ?array $phases = null,
        ?array $sessions = null,
        ?array $sets = null,
        array $feedback = [],
        ?array $planWeeks = null,
        ?array $dayTargets = null,
        array $trainingDays = [1, 4],
        bool $withPlan = true,
        string $experience = 'intermediate',
    ): TrainSnapshot {
        return new TrainSnapshot(
            today: TrainSnapshot::day($today),
            plan: $withPlan ? (new Plan)->forceFill(['user_id' => 1, 'start_date' => '2026-09-14', 'review_weekday' => 1]) : null,
            profile: (new Profile)->forceFill([
                'user_id' => 1,
                'experience' => $experience,
                'training_days' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'start' => '17:30', 'minutes' => 60], $trainingDays),
            ]),
            mesocycles: new Collection($mesocycles ?? [self::mesocycle()]),
            phases: new Collection($phases ?? [self::phase(self::GAIN, 'muscleGain', '2026-07-27', null)]),
            sessions: new Collection($sessions ?? self::sessions()),
            sets: new Collection($sets ?? self::sets()),
            feedback: new Collection($feedback),
            planWeeks: new Collection($planWeeks ?? [
                self::planWeek('2026-09-28', dietBreak: true),
                self::planWeek('2026-10-12', deload: true),
            ]),
            dayTargets: new Collection($dayTargets ?? [
                self::dayTarget('2026-09-21', 2400),
                self::dayTarget('2026-09-22', 2200),
                self::dayTarget('2026-10-05', 2000),
            ]),
            exercises: new Collection(self::exercises()),
        );
    }

    /** @param  array<string, mixed>  $attributes */
    public static function mesocycle(array $attributes = []): Mesocycle
    {
        return (new Mesocycle)->forceFill(array_replace([
            'id' => self::MESO,
            'user_id' => 1,
            'phase_id' => self::GAIN,
            'name' => 'Meso 2',
            'weeks' => 5,
            'start_date' => '2026-09-14',
            'ended_on' => null,
            'is_draft' => false,
            'is_resensitization' => false,
            'route' => null,
            'active_rest' => false,
            'days' => self::days(),
            'muscle_priorities' => ['chest' => 'emphasize', 'back' => 'grow', 'forearms' => null],
            'earlier_weeks' => [],
            'later_weeks' => [],
        ], $attributes));
    }

    /** @return list<array<string, mixed>> */
    public static function days(): array
    {
        return [
            ['id' => 'upper-a', 'label' => 'Upper A', 'weekday' => 1, 'slots' => [
                self::slot('ua-1', self::BENCH, 'chest', 3),
                self::slot('ua-2', self::ROW, 'back', 3),
                self::slot('ua-3', self::CURL, 'biceps', 2),
            ]],
            ['id' => 'lower-a', 'label' => 'Lower A', 'weekday' => 4, 'slots' => [
                self::slot('la-1', self::SQUAT, 'quads', 3),
                self::slot('la-2', self::SHRUG, 'traps', 2),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function slot(string $id, ?int $exercise, string $muscle, int $sets): array
    {
        return ['id' => $id, 'exercise_id' => $exercise, 'muscle_group' => $muscle, 'sets' => $sets];
    }

    public static function phase(string $id, string $type, string $start, ?string $end): Phase
    {
        return (new Phase)->forceFill(['id' => $id, 'user_id' => 1, 'type' => $type, 'start_date' => $start, 'end_date' => $end]);
    }

    public static function planWeek(string $start, bool $deload = false, bool $dietBreak = false, bool $dietBreakOff = false): PlanWeek
    {
        return (new PlanWeek)->forceFill([
            'id' => "pw-{$start}", 'user_id' => 1, 'start_date' => $start, 'is_deload' => $deload,
            'is_diet_break' => $dietBreak, 'diet_break_off' => $dietBreakOff, 'is_away' => false,
        ]);
    }

    public static function dayTarget(string $date, float $kcal): DayTarget
    {
        return (new DayTarget)->forceFill(['id' => "dt-{$date}", 'user_id' => 1, 'date' => $date, 'kcal' => $kcal]);
    }

    public static function session(string $id, int $week, int $day, ?string $start = null, ?string $end = null, bool $skipped = false, string $mesocycle = self::MESO): WorkoutSession
    {
        return (new WorkoutSession)->forceFill([
            'id' => $id, 'user_id' => 1, 'mesocycle_id' => $mesocycle, 'week' => $week, 'day' => $day,
            'actual_start_at' => $start, 'actual_end_at' => $end, 'is_skipped' => $skipped,
        ]);
    }

    /** @return list<WorkoutSession> */
    public static function sessions(): array
    {
        return [
            self::session('m2w2d1', 2, 1, '2026-09-21 17:30:00', '2026-09-21 18:30:00'),
            self::session('m2w4d1', 4, 1, '2026-10-05 17:30:00', '2026-10-05 18:30:00'),
        ];
    }

    public static function set(string $session, int $exercise, int $position, bool $logged = true, bool $skipped = false): WorkoutSet
    {
        return (new WorkoutSet)->forceFill([
            'id' => "{$session}-{$position}", 'user_id' => 1, 'workout_session_id' => $session, 'exercise_id' => $exercise,
            'position' => $position, 'type' => 'regular', 'actual_weight_kg' => 80.0, 'actual_reps' => 10,
            'is_logged' => $logged, 'is_skipped' => $skipped,
        ]);
    }

    /** @return list<WorkoutSet> */
    public static function sets(): array
    {
        $sets = [];
        foreach ([[self::BENCH, 3], [self::ROW, 3], [self::CURL, 2]] as [$exercise, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $sets[] = self::set('m2w2d1', $exercise, count($sets) + 1);
            }
        }
        // A skipped set and one never logged: neither counts.
        $sets[] = self::set('m2w2d1', self::BENCH, 20, skipped: true);
        $sets[] = self::set('m2w2d1', self::BENCH, 21, logged: false);
        foreach ([[self::BENCH, 3], [self::CURL, 2]] as [$exercise, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $sets[] = self::set('m2w4d1', $exercise, count($sets) + 1);
            }
        }

        return $sets;
    }

    /** One feedback sheet; a flat one is a low pump and never sore (PROG-054). */
    public static function feedback(string $session, string $muscle, string $pump = 'low', string $soreness = 'neverGotSore'): MuscleFeedback
    {
        return (new MuscleFeedback)->forceFill([
            'id' => "{$session}-{$muscle}", 'user_id' => 1, 'workout_session_id' => $session,
            'muscle_group' => $muscle, 'pump' => $pump, 'soreness' => $soreness,
        ]);
    }

    /** @return list<Exercise> */
    public static function exercises(): array
    {
        $rows = [
            [self::BENCH, 'Bench Press', 'chest'],
            [self::ROW, 'Barbell Row', 'back'],
            [self::CURL, 'Dumbbell Curl', 'biceps'],
            [self::SQUAT, 'Back Squat', 'quads'],
            [self::SHRUG, 'Barbell Shrug', 'traps'],
            [17, 'Cable Fly', 'chest'],
        ];

        return array_map(fn (array $row) => (new Exercise)->forceFill([
            'id' => $row[0], 'name' => $row[1], 'muscle_group' => $row[2], 'equipment' => 'barbell',
            'load_type' => 'barbell', 'sfr_rating' => 'medium', 'is_compound' => true,
        ]), $rows);
    }
}
