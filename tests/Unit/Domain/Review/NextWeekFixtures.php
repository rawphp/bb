<?php

namespace Tests\Unit\Domain\Review;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile;
use App\Domain\Model\Session;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Review\ReviewWorld;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekSchedule;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\WeekBuilder\WeekBuilderFixtures;

/** The helpers of rp-clone's ReviewNextWeekTests. */
final class NextWeekFixtures
{
    public const ACC = 1e-6;

    public static function profile(): Profile
    {
        return new Profile(
            name: 'Test', sex: Sex::Male, birthDate: PlanFixtures::date(1990, 6, 15), heightCm: 180, weightKg: 90,
            weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm, bodyFatBucket: BodyFatBucket::Low, usesGLP1: false,
            experience: ExperienceLevel::Intermediate,
        );
    }

    /** BMI 16.6. */
    public static function thin(): Profile
    {
        return new Profile(
            name: 'Test', sex: Sex::Male, birthDate: PlanFixtures::date(1990, 6, 15), heightCm: 170, weightKg: 48,
            weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm, bodyFatBucket: BodyFatBucket::Low, usesGLP1: false,
            experience: ExperienceLevel::Intermediate,
        );
    }

    public static function session(string $name): Session
    {
        return new Session($name, $name, [
            new ExerciseSlot('bench', 'Bench press', MuscleGroup::Chest, 4),
            new ExerciseSlot('row', 'Row', MuscleGroup::Back, 4),
        ]);
    }

    /** Monday, Wednesday and Friday (calendar weekdays, 1 = Sunday), steps by ISO weekday. */
    public static function schedule(bool $trainingOnly = false): WeekSchedule
    {
        $days = array_map(
            fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 0)),
            [2, 4, 6],
        );

        return new WeekSchedule($days, $trainingOnly ? null : new NutritionSchedule(
            steps: array_map(fn (int $weekday) => new StepPlanDay($weekday, 6_000, 8_000), range(1, 7)),
            eatingWindow: new ClockRange(ClockTime::at(7), ClockTime::at(21)),
            mealsPerDay: 4,
        ));
    }

    /**
     * Fat loss at -0.5% a week; a 5-week mesocycle from week 0 (its deload is week 4); next week is week 2.
     *
     * @param  array<string, OtherActivity>  $otherActivities
     */
    public static function world(
        int $nextWeek = 2,
        int $mesocycleWeeks = 5,
        ?AwayWeek $away = null,
        bool $dietBreak = false,
        bool $trainingOnly = false,
        PhaseType $phase = PhaseType::FatLoss,
        ?Profile $profile = null,
        array $otherActivities = [],
        string $zone = 'UTC',
    ): ReviewWorld {
        $grid = new PlanWeekGrid(PlanFixtures::date(2026, 1, 5, zone: $zone), PlanFixtures::MONDAY);

        return new ReviewWorld(
            plan: PlanFixtures::plan([PlanFixtures::phase($phase, 0, 12, $phase === PhaseType::FatLoss ? -0.5 : 0, $grid)], $grid),
            profile: $profile ?? self::profile(),
            schedule: self::schedule($trainingOnly),
            mesocycle: new Mesocycle(
                id: 'meso', phase: $phase, weeks: $mesocycleWeeks,
                sessions: [self::session('A'), self::session('B'), self::session('C')], musclePriorities: [],
            ),
            mesocycleStartWeek: 0, nextWeek: $nextWeek, newId: WeekBuilderFixtures::newId(), away: $away, storedDietBreak: $dietBreak,
            otherActivities: $otherActivities,
        );
    }

    /** @return list<float> */
    public static function kcal(BuiltWeek $week): array
    {
        return array_map(fn (WeekDay $day) => $day->target->kcal, $week->days);
    }
}
