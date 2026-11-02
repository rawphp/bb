<?php

namespace Tests\Support;

use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\OffPlanEntry;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Str;

/**
 * A user part way through a plan, stored, for the HTTP seam of progress_show and exercise_history_show. Use it with
 * the clock at Saturday 2026-10-10 12:00, the plan's review day.
 *
 * The plan starts Tuesday 2026-08-04 and reviews on Saturdays. Maintenance ran to 2026-09-01 and Fat loss (-0.5% a
 * week) runs to 2026-10-27. Base (4 weeks from 08-04) ran in Maintenance, Cut A (4 weeks from 09-01) and Cut B (6
 * weeks from 09-29) in Fat loss, and a draft has no dates. There is a weigh-in of 80 kg a day from 09-01 for 40
 * days, four of them with a waist; steps today; targets of 2650 kcal for the week with 2410 kcal eaten today.
 * Cut A holds Bench Press on 2026-10-05 (60 kg x 9, 62.5 kg x 8) and 2026-10-08 (65 kg x 8, 65 kg x 7, 62.5 kg x 8),
 * a Curl, and a Barbell Row on 2026-10-09.
 */
final class ProgressSeed
{
    public const NOW = '2026-10-10 12:00:00';

    /** @return array{user: User, bench: Exercise, row: Exercise, curl: Exercise, benchSessions: list<WorkoutSession>} */
    public static function world(): array
    {
        $user = self::planned(User::factory()->create());
        $hold = Phase::factory()->for($user)->create(['type' => 'maintenance', 'start_date' => '2026-08-04', 'end_date' => '2026-09-01', 'target_rate_percent_per_week' => 0]);
        $cut = Phase::factory()->for($user)->create(['type' => 'fatLoss', 'start_date' => '2026-09-01', 'end_date' => '2026-10-27', 'target_rate_percent_per_week' => -0.5]);
        self::mesocycle($user, 'Base', $hold->id, '2026-08-04', 4);
        $cutA = self::mesocycle($user, 'Cut A', $cut->id, '2026-09-01', 4);
        self::mesocycle($user, 'Cut B', $cut->id, '2026-09-29', 6);
        Mesocycle::factory()->for($user)->create(['name' => null, 'weeks' => null, 'start_date' => null, 'is_draft' => true]);

        foreach (range(0, 39) as $offset) {
            $waist = [0 => 88.0, 14 => 87.5, 28 => 87.5, 39 => 87.0][$offset] ?? null;
            WeighIn::factory()->for($user)->create([
                'weight_kg' => 80, 'waist_cm' => $waist, 'weighed_at' => date('Y-m-d', strtotime("2026-09-01 +{$offset} days")).' 07:00:00',
            ]);
        }
        Step::factory()->for($user)->create(['date' => '2026-10-10', 'count' => 6240]);
        TodaySeed::targets($user, last: '2026-10-16', kcal: 2650);
        OffPlanEntry::factory()->for($user)->create(['date' => '2026-10-10', 'kcal' => 2410, 'protein_g' => 170]);

        $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
        $row = TrainSeed::exercise('Barbell Row', MuscleGroup::Back);
        $curl = TrainSeed::exercise('Curl', MuscleGroup::Biceps);
        $first = self::session($user, $cutA, 1, '2026-10-05 09:00:00', [[$bench, 60, 9], [$bench, 62.5, 8], [$curl, 40, 10]]);
        $second = self::session($user, $cutA, 2, '2026-10-08 09:00:00', [[$bench, 65, 8], [$bench, 65, 7], [$bench, 62.5, 8]]);
        self::session($user, $cutA, 2, '2026-10-09 09:00:00', [[$row, 70, 10]]);

        return ['user' => $user, 'bench' => $bench, 'row' => $row, 'curl' => $curl, 'benchSessions' => [$first, $second]];
    }

    /** A user with a plan and a profile and nothing else. */
    public static function planned(User $user, string $start = '2026-08-04'): User
    {
        Plan::factory()->for($user)->create(['start_date' => $start, 'review_weekday' => 6]);
        Profile::factory()->for($user)->create([
            'weight_unit' => 'kg', 'height_unit' => 'cm',
            'step_plan' => [['weekday' => 6, 'min' => 8000, 'max' => 10000], ['weekday' => 2, 'min' => 7000, 'max' => 9000]],
        ]);

        return $user;
    }

    /**
     * Another user whose rows differ in everything the Progress tab shows: pounds, a custom exercise, 40 weigh-ins of
     * 100 kg from 2026-09-01, steps, a phase, a mesocycle and sets. Made first, so an unscoped query would find it.
     */
    public static function stranger(): User
    {
        $user = self::planned(User::factory()->create());
        Profile::query()->where('user_id', $user->id)->update(['weight_unit' => 'lb']);
        $phase = Phase::factory()->for($user)->create(['type' => 'muscleGain', 'start_date' => '2026-08-04', 'end_date' => '2026-09-08', 'target_rate_percent_per_week' => 0.25]);
        $mesocycle = self::mesocycle($user, 'Stranger block', $phase->id, '2026-08-04', 4);
        foreach (range(0, 39) as $offset) {
            WeighIn::factory()->for($user)->create(['weight_kg' => 100, 'weighed_at' => date('Y-m-d', strtotime("2026-09-01 +{$offset} days")).' 07:00:00']);
        }
        Step::factory()->for($user)->create(['date' => '2026-10-10', 'count' => 12345]);
        $custom = Exercise::factory()->custom($user)->create(['name' => 'Stranger press', 'muscle_group' => MuscleGroup::Chest]);
        self::session($user, $mesocycle, 1, '2026-10-09 09:00:00', [[$custom, 100, 10]]);
        self::session($user, $mesocycle, 1, '2026-10-08 09:00:00', [[TrainSeed::exercise('Bench Press', MuscleGroup::Chest), 100, 10]]);

        return $user;
    }

    public static function mesocycle(User $user, string $name, string $phaseId, string $start, int $weeks): Mesocycle
    {
        return Mesocycle::factory()->for($user)->create([
            'id' => (string) Str::uuid(), 'name' => $name, 'phase_id' => $phaseId, 'start_date' => $start, 'weeks' => $weeks,
        ]);
    }

    /**
     * A session with logged sets.
     *
     * @param  list<array{0: Exercise, 1: float|int, 2: int}>  $sets  exercise, kg, reps
     */
    public static function session(User $user, Mesocycle $mesocycle, int $week, string $at, array $sets): WorkoutSession
    {
        $session = WorkoutSession::factory()->for($user)->create([
            'mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => 1, 'actual_start_at' => $at, 'planned_start_at' => null,
        ]);
        foreach ($sets as $position => [$exercise, $kg, $reps]) {
            WorkoutSet::factory()->create([
                'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => $position + 1,
                'actual_weight_kg' => $kg, 'actual_reps' => $reps, 'is_logged' => true,
            ]);
        }

        return $session;
    }
}
