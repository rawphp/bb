<?php

namespace Tests\Unit\Capabilities\Reads\Workout;

use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Workout\WorkoutSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * rp-clone's WorkoutFixtures as unsaved models, so the workout read runs without a database.
 *
 * - "Meso 2" is 5 weeks from Monday 2026-09-14 under Muscle gain: weeks 1 to 4 train (RIR 3, 2, 1, 0), week 5 is
 *   the deload. Day 1 "Upper A" (Monday): Bench Press 3 sets, Barbell Row 3, Dumbbell Curl 2. Day 2 "Lower A"
 *   (Thursday): Back Squat 3, Barbell Shrug 2. The bench slot carries 80 kg x 10 from the old week 1 (MESO-061).
 * - Logged: week 2 day 1 Bench Press 80 kg for 10, 9, 8; Row 60 kg for 10, 9, 8; Curl 14 kg for 12 and 10. Week 4
 *   day 1 Bench Press 90 kg for 8, 7, 6 and Curl 14 kg for 12 and 10; day 2 Back Squat 100 kg for 10, 9, 8.
 * - The user trains Monday 17:30 for 75 minutes and Thursday 17:30 for 60. On Monday 28 September the meals are at
 *   08:00 (Breakfast, 100 g carbs), 12:30 (Lunch, 125 g) and 19:30 (unnamed, 95 g).
 * - Now is Monday 2026-09-28 17:35, week 3.
 */
final class WorkoutWorld
{
    public const MESO = 'aaaaaaaa-0000-4000-8000-000000000002';

    public const GAIN = 'bbbbbbbb-0000-4000-8000-000000000001';

    public const BENCH = 12;

    public const ROW = 25;

    public const CURL = 40;

    public const SQUAT = 30;

    public const SHRUG = 50;

    public const PULL_UP = 70;

    /**
     * @param  list<WorkoutSession>|null  $sessions  the logged history when null
     * @param  list<WorkoutSet>|null  $sets  the logged history's sets when null
     * @param  list<WorkoutExercise>  $blocks
     * @param  list<MuscleFeedback>  $muscleFeedback
     * @param  list<ExerciseFeedback>  $exerciseFeedback
     * @param  list<WorkoutSession>|null  $history  the sessions FB-007 and FB-009 look at; the logged history when null
     * @param  list<WorkoutSet>|null  $historySets
     * @param  list<ExerciseLoadStep>  $loadSteps
     * @param  list<ExerciseNote>  $notes
     * @param  list<DayTarget>|null  $dayTargets  Monday 28 September's when null
     * @param  array<string, mixed>  $meso
     * @param  array<string, mixed>  $profile
     * @param  array<int, array<string, mixed>>  $exercises  attributes over the library's, by id
     */
    public static function snapshot(
        int $week = 3,
        int $day = 1,
        string $now = '2026-09-28 17:35',
        ?array $sessions = null,
        ?array $sets = null,
        array $blocks = [],
        array $muscleFeedback = [],
        array $exerciseFeedback = [],
        ?array $history = null,
        ?array $historySets = null,
        array $loadSteps = [],
        array $notes = [],
        ?array $dayTargets = null,
        array $meso = [],
        string $phase = 'muscleGain',
        array $profile = [],
        array $exercises = [],
    ): WorkoutSnapshot {
        $sessions ??= self::sessions();
        $sets ??= self::sets();
        $clock = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $train = new TrainSnapshot(
            today: TrainSnapshot::day($clock),
            plan: (new Plan)->forceFill(['user_id' => 1, 'start_date' => '2026-09-14', 'review_weekday' => 1]),
            profile: (new Profile)->forceFill(array_replace([
                'user_id' => 1,
                'experience' => 'intermediate',
                'weight_unit' => 'kg',
                'micro_plates' => false,
                'training_days' => [
                    ['weekday' => 1, 'start' => '17:30', 'minutes' => 75],
                    ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
                ],
            ], $profile)),
            mesocycles: new Collection([self::mesocycle($meso)]),
            phases: new Collection([(new Phase)->forceFill([
                'id' => self::GAIN, 'user_id' => 1, 'type' => $phase, 'start_date' => '2026-01-05', 'end_date' => null,
            ])]),
            sessions: new Collection($sessions),
            sets: new Collection($sets),
            feedback: new Collection($muscleFeedback),
            planWeeks: new Collection,
            dayTargets: new Collection,
            exercises: new Collection(self::exercises($exercises)),
        );

        return new WorkoutSnapshot(
            train: $train,
            mesocycle: $train->mesocycle(self::MESO),
            week: $week,
            day: $day,
            blockRows: new Collection($blocks),
            exerciseFeedback: new Collection($exerciseFeedback),
            history: new Collection($history ?? $sessions),
            historySets: new Collection($historySets ?? $sets),
            loadSteps: new Collection($loadSteps),
            notes: new Collection($notes),
            dayTargets: new Collection($dayTargets ?? [self::dayTarget('2026-09-28')]),
            now: $clock,
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
            'active_rest' => false,
            'days' => self::days(),
            'muscle_priorities' => ['chest' => 'emphasize'],
            'earlier_weeks' => [],
            'later_weeks' => [],
        ], $attributes));
    }

    /** @return list<array<string, mixed>> */
    public static function days(): array
    {
        return [
            ['id' => 'day-1', 'label' => 'Upper A', 'weekday' => 1, 'slots' => [
                self::slot(self::BENCH, 'chest', 3, ['weight_kg' => 80.0, 'reps' => 10]),
                self::slot(self::ROW, 'back', 3),
                self::slot(self::CURL, 'biceps', 2),
            ]],
            ['id' => 'day-2', 'label' => 'Lower A', 'weekday' => 4, 'slots' => [
                self::slot(self::SQUAT, 'quads', 3),
                self::slot(self::SHRUG, 'traps', 2),
            ]],
        ];
    }

    /**
     * @param  ?array{weight_kg: float, reps: int}  $carried
     * @return array<string, mixed>
     */
    public static function slot(?int $exercise, string $muscle, int $sets, ?array $carried = null): array
    {
        return ['id' => 'slot-'.($exercise ?? $muscle), 'exercise_id' => $exercise, 'muscle_group' => $muscle, 'sets' => $sets, 'carried' => $carried];
    }

    /** @param  array<string, mixed>  $attributes */
    public static function session(string $id, int $week, int $day, ?string $start = null, ?string $end = null, bool $skipped = false, array $attributes = []): WorkoutSession
    {
        return (new WorkoutSession)->forceFill(array_replace([
            'id' => $id, 'user_id' => 1, 'mesocycle_id' => self::MESO, 'week' => $week, 'day' => $day,
            'planned_start_at' => null, 'planned_end_at' => null,
            'actual_start_at' => $start, 'actual_end_at' => $end, 'is_skipped' => $skipped,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    public static function set(string $id, string $session, int $exercise, int $position, array $attributes = []): WorkoutSet
    {
        return (new WorkoutSet)->forceFill(array_replace([
            'id' => $id, 'user_id' => 1, 'workout_session_id' => $session, 'workout_exercise_id' => null,
            'exercise_id' => $exercise, 'position' => $position, 'type' => 'regular', 'variant' => 'straight',
            'target_weight_kg' => null, 'target_reps' => null, 'actual_weight_kg' => null, 'actual_reps' => null,
            'is_logged' => false, 'is_skipped' => false,
        ], $attributes));
    }

    public static function logged(string $id, string $session, int $exercise, int $position, float $kg, int $reps): WorkoutSet
    {
        return self::set($id, $session, $exercise, $position, ['actual_weight_kg' => $kg, 'actual_reps' => $reps, 'is_logged' => true]);
    }

    public static function block(string $id, string $session, int $exercise, int $position, ?string $note = null): WorkoutExercise
    {
        return (new WorkoutExercise)->forceFill([
            'id' => $id, 'user_id' => 1, 'workout_session_id' => $session, 'exercise_id' => $exercise,
            'position' => $position, 'note' => $note,
        ]);
    }

    /** @return list<WorkoutSession> */
    public static function sessions(): array
    {
        return [
            self::session('m2w2d1', 2, 1, '2026-09-21 17:30:00', '2026-09-21 18:30:00'),
            self::session('m2w4d1', 4, 1, '2026-10-05 17:30:00', '2026-10-05 18:30:00'),
            self::session('m2w4d2', 4, 2, '2026-10-08 17:30:00', '2026-10-08 18:30:00'),
        ];
    }

    /** @return list<WorkoutSet> */
    public static function sets(): array
    {
        $sets = [];
        foreach ([10, 9, 8] as $index => $reps) {
            $sets[] = self::logged("w2-bench-{$index}", 'm2w2d1', self::BENCH, $index + 1, 80, $reps);
            $sets[] = self::logged("w2-row-{$index}", 'm2w2d1', self::ROW, $index + 4, 60, $reps);
        }
        foreach ([12, 10] as $index => $reps) {
            $sets[] = self::logged("w2-curl-{$index}", 'm2w2d1', self::CURL, $index + 7, 14, $reps);
            $sets[] = self::logged("w4-curl-{$index}", 'm2w4d1', self::CURL, $index + 7, 14, $reps);
        }
        foreach ([8, 7, 6] as $index => $reps) {
            $sets[] = self::logged("w4-bench-{$index}", 'm2w4d1', self::BENCH, $index + 1, 90, $reps);
        }
        foreach ([10, 9, 8] as $index => $reps) {
            $sets[] = self::logged("w4-squat-{$index}", 'm2w4d2', self::SQUAT, $index + 1, 100, $reps);
        }
        usort($sets, fn (WorkoutSet $a, WorkoutSet $b) => $a->position <=> $b->position);

        return $sets;
    }

    public static function dayTarget(string $date): DayTarget
    {
        return (new DayTarget)->forceFill([
            'id' => "dt-{$date}", 'user_id' => 1, 'date' => $date, 'kcal' => 2600,
            'meals' => [
                ['id' => 'm1', 'name' => 'Breakfast', 'time' => '08:00', 'carbs_g' => 100],
                ['id' => 'm2', 'name' => 'Lunch', 'time' => '12:30', 'carbs_g' => 125],
                ['id' => 'm3', 'name' => null, 'time' => '19:30', 'carbs_g' => 95],
            ],
        ]);
    }

    public static function muscleFeedback(string $session, string $muscle, ?string $soreness, ?string $pump, ?string $volume): MuscleFeedback
    {
        return (new MuscleFeedback)->forceFill([
            'id' => "{$session}-{$muscle}", 'user_id' => 1, 'workout_session_id' => $session,
            'muscle_group' => $muscle, 'soreness' => $soreness, 'pump' => $pump, 'volume' => $volume,
        ]);
    }

    public static function jointPain(string $session, int $exercise, string $answer): ExerciseFeedback
    {
        return (new ExerciseFeedback)->forceFill([
            'id' => "{$session}-{$exercise}", 'user_id' => 1, 'workout_session_id' => $session,
            'exercise_id' => $exercise, 'joint_pain' => $answer,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     * @return list<Exercise>
     */
    public static function exercises(array $overrides = []): array
    {
        $rows = [
            self::BENCH => ['Bench Press', 'chest', 'barbell', 'barbell', 5, 10],
            self::ROW => ['Barbell Row', 'back', 'barbell', 'barbell', 5, 10],
            self::CURL => ['Dumbbell Curl', 'biceps', 'dumbbell', 'dumbbell', 10, 20],
            self::SQUAT => ['Back Squat', 'quads', 'barbell', 'barbell', 5, 10],
            self::SHRUG => ['Barbell Shrug', 'traps', 'barbell', 'barbell', 10, 20],
            self::PULL_UP => ['Pull-up', 'back', 'bodyweight', 'bodyweight', 5, 10],
        ];

        $exercises = [];
        foreach ($rows as $id => [$name, $muscle, $equipment, $load, $min, $max]) {
            $exercises[] = (new Exercise)->forceFill(array_replace([
                'id' => $id, 'user_id' => null, 'name' => $name, 'muscle_group' => $muscle, 'equipment' => $equipment,
                'load_type' => $load, 'sfr_rating' => 'medium', 'rep_range_min' => $min, 'rep_range_max' => $max,
                'is_compound' => true,
            ], $overrides[$id] ?? []));
        }

        return $exercises;
    }
}
