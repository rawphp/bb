<?php

namespace Tests\Support;

use App\Domain\Model\MuscleGroup;
use App\Domain\Model\WeighIn as DomainWeighIn;
use App\Domain\Weight\TrendWeight;
use App\Models\DayTarget;
use App\Models\ExerciseFeedback;
use App\Models\MealCheckIn;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewMessages;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A small plan world for the weekly review, stored (rp-clone's ReviewScenario in ReviewFixtures.swift).
 *
 * The week the review sets starts Monday 2026-10-12 (plan week `weeksFinished`); the week reviewed is 5 to 11
 * October. The plan starts `weeksFinished` weeks before, reviews on Mondays, and holds one fat-loss phase and one
 * mesocycle from its start. The user trains Monday, Wednesday and Friday, weighs in every day on the plan's rate
 * (the schedule says Monday and Thursday), and eats four meals a day. Training days target 2,600 kcal and rest days
 * 2,250, so a week averages 2,400.
 *
 * Set the public fields, then call seed().
 */
final class ReviewSeed
{
    public const NOW = '2026-10-12 09:00:00';

    public const TODAY = '2026-10-12';

    public const NEXT_WEEK_START = '2026-10-12';

    /** Plan weeks that have finished; the next week is this plan week. */
    public int $weeksFinished = 4;

    public string $phaseType = 'fatLoss';

    public float $ratePercent = -0.5;

    /** The phase's end date (exclusive); null for no end. */
    public ?string $phaseEndDate = null;

    public int $mesocycleWeeks = 6;

    public bool $trainingOnly = false;

    public float $heightCm = 180;

    /** The profile's stored weight. */
    public float $profileKg = 90;

    /** @var list<int> the weigh-in schedule's ISO weekdays */
    public array $weighInWeekdays = [1, 4];

    /** @var list<string> dates with a stored Other activity */
    public array $otherActivityDates = [];

    /** @var Closure(int): ?float the weight on a day, by days from the next week's start (-1 is 11 October); null is no weigh-in */
    public Closure $weight;

    /** @var Closure(string, int): bool whether a meal is checked in: date and meal number (1 to 4) */
    public Closure $isChecked;

    /** The share of a checked-in meal's target that was eaten. */
    public float $eatenShare = 1.0;

    /** @var list<array<string, mixed>> plan_weeks rows to store, each with a start_date and its flags */
    public array $planWeekRows = [];

    /** Day targets stored for the next week too. */
    public bool $nextWeekHasTargets = false;

    /** The reviewed week's sessions: how many were done, and how many skipped (the rest are missed). */
    public int $sessionsDone = 3;

    public int $sessionsSkipped = 0;

    /** Both chest sessions of the week missed their rep targets by 4 (PROG-050). */
    public bool $chestUnderperforms = false;

    /** Both back sessions of the week missed their rep targets by 4 (PROG-050). */
    public bool $backUnderperforms = false;

    /** Sets in the Monday chest slot. */
    public int $chestSlotSets = 4;

    /** @var list<array{session: string, muscle: string, soreness?: ?string, volume?: ?string}> by session key (sessionKey(), earlierKey()) */
    public array $muscleFeedback = [];

    /** @var list<string> dates of done sessions in earlier weeks (one each); their keys are earlierKey(index) */
    public array $earlierSessionDates = [];

    /** @var list<array{session: string, exercise: string, pain: string}> joint pain by session key and exercise name */
    public array $exerciseFeedback = [];

    /** @var list<array{weekday: int, min: int, max: int}> */
    public array $stepPlan;

    /** @var array<string, int> step counts by date */
    public array $steps = [];

    /** Moves every day of the reviewed week's stored target by this many kcal (all of it in carbs). */
    public float $reviewedWeekShiftKcal = 0;

    /** @var array<string, string> the stored session ids by key, after seed() */
    public array $sessionIds = [];

    public function __construct()
    {
        // 0.5% a week of 90 kg, the plan's own rate, with a weigh-in every day of the plan.
        $this->weight = fn (int $offset): float => 90 - 0.0643 * ($offset + 28);
        $this->isChecked = fn (string $date, int $meal): bool => true;
        $this->stepPlan = array_map(fn (int $weekday) => ['weekday' => $weekday, 'min' => 6000, 'max' => 8000], range(1, 7));
    }

    // MARK: dates

    public static function day(int $offset): string
    {
        return (new DateTimeImmutable(self::NEXT_WEEK_START, new DateTimeZone('UTC')))->modify("{$offset} days")->format('Y-m-d');
    }

    public function planStart(): string
    {
        return self::day(-7 * $this->weeksFinished);
    }

    /** @return list<string> every date from the plan start to the day before next week, and next week too when it is saved */
    public function storedDays(): array
    {
        $last = $this->nextWeekHasTargets ? 6 : -1;

        return array_map(fn (int $offset) => self::day($offset), range(-7 * $this->weeksFinished, $last));
    }

    /** The reviewed week's session on mesocycle day 1, 2 or 3. */
    public static function sessionKey(int $day): string
    {
        return "s{$day}";
    }

    public static function earlierKey(int $index): string
    {
        return "e{$index}";
    }

    /**
     * High training fatigue (REV-040) as one session's feedback says: two of four answers are "still sore" or "too much".
     *
     * @return list<array{session: string, muscle: string, soreness: string, volume: string}>
     */
    public static function highFatigue(string $session): array
    {
        return [
            ['session' => $session, 'muscle' => 'chest', 'soreness' => 'stillSore', 'volume' => 'tooMuch'],
            ['session' => $session, 'muscle' => 'back', 'soreness' => 'stillSore', 'volume' => 'justRight'],
        ];
    }

    /** The trend the seeded weigh-ins give. */
    public function trend(): TrendWeight
    {
        $readings = [];
        foreach (range(-7 * ($this->weeksFinished + 1), 0) as $offset) {
            $kg = $this->storedKg($offset);
            if ($kg !== null) {
                $readings[] = new DomainWeighIn("w{$offset}", new DateTimeImmutable(self::day($offset).' 07:00:00', new DateTimeZone('UTC')), $kg);
            }
        }

        return new TrendWeight($readings, new DateTimeZone('UTC'));
    }

    /** Seeds a new user and reviews on `$today`. */
    public function analysis(string $today = self::TODAY): ?ReviewAnalysis
    {
        return ReviewAnalysis::for($this->seed(), $today);
    }

    /** The words the review gives its recommendation, against the reviewed week's target. */
    public static function reasonText(ReviewAnalysis $analysis): string
    {
        $recommendation = $analysis->recommendation ?? throw new RuntimeException('There is no recommendation.');
        $reviewed = $analysis->weeks[count($analysis->weeks) - 1];

        return ReviewMessages::reason($recommendation->reason, $reviewed->targetKcal);
    }

    // MARK: rows

    public function seed(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $start = $this->planStart();
        Plan::factory()->for($user)->create(['start_date' => $start, 'review_weekday' => 1]);
        Profile::factory()->for($user)->create([
            'name' => 'Sam', 'sex' => 'male', 'birth_date' => '1990-06-15', 'height_cm' => $this->heightCm, 'weight_kg' => $this->profileKg,
            'body_fat_bucket' => 'low', 'uses_glp1' => false, 'experience' => 'intermediate', 'weight_unit' => 'kg',
            'height_unit' => 'cm', 'consumed_totals' => 'checked_in', 'eating_window' => ['start' => '07:00', 'end' => '21:00'],
            'meal_count' => 4, 'busy_periods' => [],
            'training_days' => [
                ['weekday' => 1, 'start' => '17:30', 'minutes' => 75],
                ['weekday' => 3, 'start' => '17:30', 'minutes' => 75],
                ['weekday' => 5, 'start' => '17:30', 'minutes' => 75],
            ],
            'weigh_in_days' => [['weekday' => 1, 'time' => '07:00'], ['weekday' => 4, 'time' => '07:00']],
            'step_plan' => $this->stepPlan, 'training_only' => $this->trainingOnly,
        ]);
        WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => $this->weighInWeekdays, 'time' => '07:00']);
        $phase = Phase::factory()->for($user)->create([
            'type' => $this->phaseType, 'start_date' => $start, 'end_date' => $this->phaseEndDate,
            'target_rate_percent_per_week' => $this->ratePercent,
        ]);
        foreach ($this->planWeekRows as $row) {
            PlanWeek::factory()->for($user)->create($row);
        }
        $this->targets($user);
        foreach (range(-7 * ($this->weeksFinished + 1), 0) as $offset) {
            $kg = $this->storedKg($offset);
            if ($kg !== null) {
                WeighIn::factory()->for($user)->create(['weight_kg' => $kg, 'weighed_at' => self::day($offset).' 07:00:00']);
            }
        }
        foreach ($this->otherActivityDates as $date) {
            OtherActivity::factory()->for($user)->create(['date' => $date]);
        }
        foreach ($this->steps as $date => $count) {
            Step::factory()->for($user)->create(['date' => $date, 'count' => $count]);
        }
        $this->training($user, $phase->id, $start);

        return $user;
    }

    /** The day's weight as the weigh_ins column keeps it, to 2 decimals. */
    private function storedKg(int $offset): ?float
    {
        $kg = ($this->weight)($offset);

        return $kg === null ? null : round($kg, 2);
    }

    /** @return array{type: string, kcal: float, carbs: float} the day's stored target; the reviewed week may be shifted */
    private function spec(string $date): array
    {
        $weekday = (int) (new DateTimeImmutable($date))->format('N');
        $base = in_array($weekday, [1, 3, 5], true)
            ? ['type' => 'moderate', 'kcal' => 2600.0, 'carbs' => 302.5]
            : ['type' => 'rest', 'kcal' => 2250.0, 'carbs' => 215.0];
        if ($this->reviewedWeekShiftKcal == 0 || $date < '2026-10-05' || $date > '2026-10-11') {
            return $base;
        }

        return ['type' => $base['type'], 'kcal' => $base['kcal'] + $this->reviewedWeekShiftKcal, 'carbs' => $base['carbs'] + $this->reviewedWeekShiftKcal / 4];
    }

    private function targets(User $user): void
    {
        $times = ['08:00', '12:00', '16:00', '19:30'];
        foreach ($this->storedDays() as $date) {
            $spec = $this->spec($date);
            $target = DayTarget::factory()->for($user)->create([
                'date' => $date, 'day_type' => $spec['type'], 'kcal' => $spec['kcal'], 'protein_g' => 190, 'fat_g' => 70,
                'carbs_g' => $spec['carbs'],
                'meals' => array_map(fn (int $index) => [
                    'name' => "Meal {$index}", 'time' => $times[$index - 1], 'kcal' => $spec['kcal'] / 4, 'protein_g' => 47.5,
                    'fat_g' => 17.5, 'carbs_g' => $spec['carbs'] / 4,
                ], range(1, 4)),
            ]);
            foreach ($target->meals as $position => $meal) {
                if (! ($this->isChecked)($date, $position + 1)) {
                    continue;
                }
                MealCheckIn::factory()->create([
                    'meal_id' => $meal['id'], 'user_id' => $user->id, 'time' => null, 'kcal' => $spec['kcal'] / 4 * $this->eatenShare,
                    'protein_g' => 47.5 * $this->eatenShare, 'fat_g' => 17.5 * $this->eatenShare,
                    'carbs_g' => $spec['carbs'] / 4 * $this->eatenShare, 'is_manual' => false,
                ]);
            }
        }
    }

    private function training(User $user, string $phaseId, string $start): void
    {
        $exercises = [
            12 => TrainSeed::exercise('Bench Press', MuscleGroup::Chest),
            25 => TrainSeed::exercise('Barbell Row', MuscleGroup::Back),
            30 => TrainSeed::exercise('Back Squat', MuscleGroup::Quads),
            31 => TrainSeed::exercise('Romanian Deadlift', MuscleGroup::Hamstrings),
        ];
        $slot = fn (int $exercise, string $muscle, int $sets) => [
            'id' => (string) Str::uuid(), 'exercise_id' => $exercises[$exercise]->id, 'muscle_group' => $muscle, 'sets' => $sets,
        ];
        $mesocycle = Mesocycle::factory()->for($user)->create([
            'id' => (string) Str::uuid(), 'phase_id' => $phaseId, 'name' => 'Meso A', 'weeks' => $this->mesocycleWeeks,
            'start_date' => $start, 'is_draft' => false, 'muscle_priorities' => [],
            'days' => [
                ['id' => (string) Str::uuid(), 'label' => 'Upper A', 'weekday' => 1, 'slots' => [$slot(12, 'chest', $this->chestSlotSets), $slot(25, 'back', 3)]],
                ['id' => (string) Str::uuid(), 'label' => 'Lower A', 'weekday' => 3, 'slots' => [$slot(30, 'quads', 4), $slot(31, 'hamstrings', 3)]],
                ['id' => (string) Str::uuid(), 'label' => 'Upper B', 'weekday' => 5, 'slots' => [$slot(12, 'chest', 3), $slot(25, 'back', 3)]],
            ],
        ]);

        $planned = [1 => [[12, $this->chestSlotSets], [25, 3]], 2 => [[30, 4], [31, 3]], 3 => [[12, 3], [25, 3]]];
        $done = [];
        foreach ($this->earlierSessionDates as $index => $date) {
            $done[self::earlierKey($index)] = [1, 1, $date];
        }
        foreach (['2026-10-05', '2026-10-07', '2026-10-09'] as $index => $date) {
            $day = $index + 1;
            if ($day <= $this->sessionsDone) {
                $done[self::sessionKey($day)] = [$this->weeksFinished, $day, $date];
            } elseif ($day <= $this->sessionsDone + $this->sessionsSkipped) {
                $this->sessionIds[self::sessionKey($day)] = WorkoutSession::factory()->for($user)->create([
                    'mesocycle_id' => $mesocycle->id, 'week' => $this->weeksFinished, 'day' => $day, 'is_skipped' => true,
                    'planned_start_at' => "{$date} 17:30:00", 'planned_end_at' => null,
                ])->id;
            }
        }
        foreach ($done as $key => [$week, $day, $date]) {
            $session = WorkoutSession::factory()->for($user)->create([
                'mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => $day, 'planned_start_at' => "{$date} 17:30:00",
                'planned_end_at' => null, 'actual_start_at' => "{$date} 17:30:00", 'actual_end_at' => "{$date} 18:45:00",
            ]);
            $this->sessionIds[$key] = $session->id;
            $position = 0;
            foreach ($planned[$day] as [$exercise, $sets]) {
                $block = WorkoutExercise::factory()->create([
                    'workout_session_id' => $session->id, 'user_id' => $user->id, 'exercise_id' => $exercises[$exercise]->id,
                ]);
                $missed = ($this->chestUnderperforms && $exercise === 12) || ($this->backUnderperforms && $exercise === 25);
                foreach (range(1, $sets) as $ignored) {
                    WorkoutSet::factory()->create([
                        'workout_session_id' => $session->id, 'user_id' => $user->id, 'workout_exercise_id' => $block->id,
                        'exercise_id' => $exercises[$exercise]->id, 'position' => ++$position, 'target_weight_kg' => 80,
                        'target_reps' => 10, 'actual_weight_kg' => 80, 'actual_reps' => $missed ? 6 : 10, 'is_logged' => true,
                    ]);
                }
            }
        }

        foreach ($this->muscleFeedback as $answer) {
            MuscleFeedback::factory()->create([
                'workout_session_id' => $this->sessionIds[$answer['session']], 'user_id' => $user->id,
                'muscle_group' => $answer['muscle'], 'soreness' => $answer['soreness'] ?? null, 'pump' => null,
                'volume' => $answer['volume'] ?? null,
            ]);
        }
        $byName = [];
        foreach ($exercises as $exercise) {
            $byName[$exercise->name] = $exercise;
        }
        foreach ($this->exerciseFeedback as $answer) {
            ExerciseFeedback::factory()->create([
                'workout_session_id' => $this->sessionIds[$answer['session']], 'user_id' => $user->id,
                'exercise_id' => $byName[$answer['exercise']]->id, 'joint_pain' => $answer['pain'],
            ]);
        }
    }
}
