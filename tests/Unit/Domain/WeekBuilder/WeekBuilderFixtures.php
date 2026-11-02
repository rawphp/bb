<?php

namespace Tests\Unit\Domain\WeekBuilder;

use App\Domain\Energy\EnergyRules;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use App\Domain\Model\Profile;
use App\Domain\Model\Session;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeightUnit;
use App\Domain\MealSplit\CarbPlacement;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Retarget\OtherActivity;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekBuilder;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use App\Domain\WeekBuilder\WeekSchedule;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Unit\Domain\Plan\PlanFixtures;

/**
 * Inputs for the week builder tests (rp-clone's WeekBuilderTests Fixture): the MacroFixtures athlete (male, 180 cm,
 * 90 kg, bucket low, intermediate) with a fixed birth date, on the PlanFixtures plan (Monday 2026-01-05, UTC).
 */
final class WeekBuilderFixtures
{
    public const ACC = 1e-6;

    public const WEIGHT_KG = 90.0;

    /** Calendar weekdays (1 = Sunday), as TrainingDay takes them. */
    public const MONDAY = 2;

    public const WEDNESDAY = 4;

    public const FRIDAY = 6;

    /** Fat loss at -0.75 %/wk of 90 kg: 0.0075 x 90 x 7700 / 7 = 742.5 kcal a day. */
    public const FAT_LOSS_CHANGE = -742.5;

    /** Muscle gain at +0.25 %/wk of 90 kg: 247.5 kcal a day. */
    public const MUSCLE_GAIN_CHANGE = 247.5;

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function profile(float $weightKg = self::WEIGHT_KG): Profile
    {
        return new Profile(
            name: 'Test', sex: Sex::Male, birthDate: PlanFixtures::date(1990, 6, 15), heightCm: 180, weightKg: $weightKg,
            weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm, bodyFatBucket: BodyFatBucket::Low, usesGLP1: false,
            experience: ExperienceLevel::Intermediate,
        );
    }

    /** 8 working sets, with traps: deloaded it is chest 2 + back 1 = 3 sets (20 minutes). */
    public static function session(string $name, int $chest = 4, int $back = 2, int $traps = 2): Session
    {
        return new Session($name, $name, [
            new ExerciseSlot('bench', 'Bench press', MuscleGroup::Chest, $chest),
            new ExerciseSlot('row', 'Row', MuscleGroup::Back, $back),
            new ExerciseSlot('shrug', 'Shrug', MuscleGroup::Traps, $traps),
        ]);
    }

    /** @param  list<Session>|null  $sessions */
    public static function mesocycle(PhaseType $type, ?array $sessions = null): Mesocycle
    {
        return new Mesocycle(
            id: 'meso', phase: $type, weeks: 5,
            sessions: $sessions ?? [self::session('A'), self::session('B'), self::session('C')], musclePriorities: [],
        );
    }

    public static function fatLossPlan(): Plan
    {
        return PlanFixtures::plan([PlanFixtures::phase(PhaseType::FatLoss, 0, 8, -0.75)]);
    }

    public static function muscleGainPlan(): Plan
    {
        return PlanFixtures::plan([PlanFixtures::phase(PhaseType::MuscleGain, 0, 8, 0.25)]);
    }

    /** @return list<TrainingDay> Monday, Wednesday and Friday at 06:30 */
    public static function trainingDays(): array
    {
        return array_map(
            fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(6, 30), 0)),
            [self::MONDAY, self::WEDNESDAY, self::FRIDAY],
        );
    }

    /**
     * 5,000 to 8,000 steps (factor 1.30) Monday to Friday, 8,000 to 10,000 (1.375) at the weekend. StepPlanDay takes
     * ISO weekdays (1 = Monday), as the server stores them.
     *
     * @return list<StepPlanDay>
     */
    public static function steps(): array
    {
        return [
            ...array_map(fn (int $weekday) => new StepPlanDay($weekday, 5_000, 8_000), range(1, 5)),
            ...array_map(fn (int $weekday) => new StepPlanDay($weekday, 8_000, 10_000), [6, 7]),
        ];
    }

    public static function window(): ClockRange
    {
        return new ClockRange(ClockTime::at(5), ClockTime::at(21));
    }

    public static function nutrition(int $meals = 6, ?ClockRange $window = null, CarbPlacement $carbPlacement = CarbPlacement::TrainingCentred): NutritionSchedule
    {
        return new NutritionSchedule(
            steps: self::steps(), eatingWindow: $window ?? self::window(), mealsPerDay: $meals, busy: [],
            carbPlacement: $carbPlacement,
        );
    }

    public static function schedule(?NutritionSchedule $nutrition = null, bool $trainingOnly = false): WeekSchedule
    {
        return new WeekSchedule(self::trainingDays(), $trainingOnly ? null : ($nutrition ?? self::nutrition()));
    }

    /** A fresh id for every meal the builder makes: "n1", "n2", ... */
    public static function newId(): Closure
    {
        $next = 0;

        return function () use (&$next): string {
            return 'n'.++$next;
        };
    }

    /** @param  array<string, OtherActivity>  $otherActivities */
    public static function build(
        ?Plan $plan = null,
        ?Profile $profile = null,
        ?Mesocycle $mesocycle = null,
        int $mesocycleStartWeek = 0,
        int $week = 0,
        ?WeekSchedule $schedule = null,
        ?AwayWeek $away = null,
        bool $isDietBreak = false,
        bool $dietBreakTurnedOff = false,
        array $otherActivities = [],
        float $calorieChangePerDay = 0,
    ): BuiltWeek {
        $plan ??= self::fatLossPlan();

        return WeekBuilder::build(
            plan: $plan, profile: $profile ?? self::profile(),
            mesocycle: $mesocycle ?? self::mesocycle($plan->phases[0]->type), mesocycleStartWeek: $mesocycleStartWeek,
            week: $week, schedule: $schedule ?? self::schedule(), newId: self::newId(), away: $away,
            isDietBreak: $isDietBreak, dietBreakTurnedOff: $dietBreakTurnedOff,
            calorieChangePerDay: $calorieChangePerDay, otherActivities: $otherActivities,
        );
    }

    /** The refusal a build threw; fails the test when it returned instead. */
    public static function refusal(Closure $build): WeekBuildRefusal
    {
        try {
            $build();
        } catch (WeekBuildRefusal $refusal) {
            return $refusal;
        }

        throw new AssertionFailedError('Expected a refusal, got a week.');
    }

    // Expected energy, straight from the energy rules

    public static function restExpenditure(DateTimeImmutable $date): float
    {
        $weekday = (int) $date->format('N');
        $day = array_values(array_filter(self::steps(), fn (StepPlanDay $step) => $step->weekday === $weekday))[0];
        $bmr = EnergyRules::bmr(self::profile(), self::WEIGHT_KG, $date, self::utc());

        return EnergyRules::dailyExpenditure($bmr, EnergyRules::activityFactorForDay($day), 0);
    }

    public static function workoutKcal(float $minutes, DayType $intensity): float
    {
        return EnergyRules::workoutEnergy($minutes, self::WEIGHT_KG, $intensity);
    }

    public static function offset(ClockTime $time): int
    {
        return self::window()->start->minutesUntil($time);
    }

    // Reading a built week

    /** @return list<float> */
    public static function kcal(BuiltWeek $built): array
    {
        return array_map(fn (WeekDay $day) => $day->target?->kcal, $built->days);
    }

    /** @return list<int> calendar weekdays (1 = Sunday) */
    public static function weekdays(BuiltWeek $built): array
    {
        return array_map(fn (WeekDay $day) => (int) $day->day->date->format('w') + 1, $built->days);
    }

    /** @return list<int> */
    public static function offsets(WeekDay $day): array
    {
        return array_map(fn (WeekMeal $meal) => self::offset($meal->time), $day->meals);
    }

    /** @return list<float> */
    public static function carbs(WeekDay $day): array
    {
        return array_map(fn (WeekMeal $meal) => $meal->meal->target->carbsG, $day->meals);
    }

    public static function assertMealsSumToDay(WeekDay $day): void
    {
        $target = $day->target;
        if ($target === null) {
            throw new AssertionFailedError('no day target');
        }
        $sum = fn (Closure $field) => array_sum(array_map(fn (WeekMeal $meal) => $field($meal->meal->target), $day->meals));
        expect($sum(fn ($t) => $t->kcal))->toEqualWithDelta($target->kcal, self::ACC)
            ->and($sum(fn ($t) => $t->proteinG))->toEqualWithDelta($target->proteinG, self::ACC)
            ->and($sum(fn ($t) => $t->fatG))->toEqualWithDelta($target->fatG, self::ACC)
            ->and($sum(fn ($t) => $t->carbsG))->toEqualWithDelta($target->carbsG, self::ACC);
    }
}
