<?php

/*
 * Ported from rp-clone's ModelCodableTests. Those tests round-trip each value through JSON; the PHP types
 * have no codec, so each test builds the value with the same inputs and reads every field back. The
 * DOM_ numbers are rp-clone's own test numbers, not docs/spec requirement IDs.
 */

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\Day;
use App\Domain\Model\DayType;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\JointPain;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Priority;
use App\Domain\Model\Profile;
use App\Domain\Model\Pump;
use App\Domain\Model\ReviewDayChange;
use App\Domain\Model\Session;
use App\Domain\Model\Sex;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeighIn;
use App\Domain\Model\WeightUnit;
use App\Domain\Model\Workout;

function modelDate(): DateTimeImmutable
{
    return (new DateTimeImmutable('@1700000000'))->setTimezone(new DateTimeZone('UTC'));
}

function benchSlot(): ExerciseSlot
{
    return new ExerciseSlot(id: 'slot-1', exerciseName: 'Bench press', muscle: MuscleGroup::Chest, sets: 3);
}

function upperA(): Session
{
    return new Session(id: 'session-1', name: 'Upper A', slots: [benchSlot()]);
}

it('DOM_001 a profile holds every field', function () {
    $profile = new Profile(
        name: 'Alex', sex: Sex::Female, birthDate: modelDate(), heightCm: 170, weightKg: 65.5,
        weightUnit: WeightUnit::Lb, heightUnit: HeightUnit::FeetInches, bodyFatBucket: BodyFatBucket::High,
        usesGLP1: true, experience: ExperienceLevel::Advanced,
    );

    expect($profile->name)->toBe('Alex')
        ->and($profile->sex)->toBe(Sex::Female)
        ->and($profile->birthDate)->toEqual(modelDate())
        ->and($profile->heightCm)->toBe(170.0)
        ->and($profile->weightKg)->toBe(65.5)
        ->and($profile->weightUnit)->toBe(WeightUnit::Lb)
        ->and($profile->heightUnit)->toBe(HeightUnit::FeetInches)
        ->and($profile->bodyFatBucket)->toBe(BodyFatBucket::High)
        ->and($profile->usesGLP1)->toBeTrue()
        ->and($profile->experience)->toBe(ExperienceLevel::Advanced);
});

it('DOM_002 a phase holds every field, with or without an event date', function () {
    $end = modelDate()->modify('+28 days');
    $miniCut = new Phase(id: 'p-1', type: PhaseType::MiniCut, start: modelDate(), end: $end, targetRatePercentPerWeek: 1.0, eventDate: modelDate());
    $maintenance = new Phase(id: 'p-2', type: PhaseType::Maintenance, start: modelDate(), end: modelDate(), targetRatePercentPerWeek: 0);

    expect($miniCut->id)->toBe('p-1')
        ->and($miniCut->type)->toBe(PhaseType::MiniCut)
        ->and($miniCut->start)->toEqual(modelDate())
        ->and($miniCut->end)->toEqual($end)
        ->and($miniCut->targetRatePercentPerWeek)->toBe(1.0)
        ->and($miniCut->eventDate)->toEqual(modelDate())
        ->and($maintenance->targetRatePercentPerWeek)->toBe(0.0)
        ->and($maintenance->eventDate)->toBeNull();
});

it('DOM_003 a plan holds its phases and review-day changes', function () {
    $phase = new Phase(id: 'p-1', type: PhaseType::FatLoss, start: modelDate(), end: modelDate(), targetRatePercentPerWeek: 0.75);
    $plain = new Plan(id: 'plan-1', start: modelDate(), reviewWeekday: 2, phases: [$phase]);
    $changed = new Plan(
        id: 'plan-2', start: modelDate(), reviewWeekday: 2,
        reviewDayChanges: [new ReviewDayChange(from: modelDate(), weekday: 6)], phases: [$phase],
    );

    expect($plain->phases)->toBe([$phase])
        ->and($plain->reviewDayChanges)->toBe([])
        ->and($plain->currentReviewWeekday())->toBe(2)
        ->and($changed->reviewDayChanges[0]->from)->toEqual(modelDate())
        ->and($changed->reviewDayChanges[0]->weekday)->toBe(6)
        ->and($changed->currentReviewWeekday())->toBe(6);
});

it('DOM_004 a plan week holds its flags and has seven days', function () {
    $week = new PlanWeek(start: modelDate(), isDeload: true, isDietBreak: false, isAway: true);
    $days = $week->days();

    expect($week->isDeload)->toBeTrue()
        ->and($week->isDietBreak)->toBeFalse()
        ->and($week->isAway)->toBeTrue()
        ->and(PlanWeek::LENGTH_IN_DAYS)->toBe(7)
        ->and($days)->toHaveCount(7)
        ->and($days[0])->toEqual(modelDate()->setTime(0, 0))
        ->and($week->end())->toEqual($days[6])
        ->and($days[0]->diff($days[6])->days)->toBe(6);
});

it('DOM_005 a day holds every day type', function (DayType $type) {
    $day = new Day(date: modelDate(), type: $type);

    expect($day->date)->toEqual(modelDate())
        ->and($day->type)->toBe($type)
        ->and(DayType::from($type->value))->toBe($type);
})->with(DayType::cases());

it('DOM_005 lists the day types in order', function () {
    expect(DayType::cases())->toBe([DayType::Rest, DayType::Light, DayType::Moderate, DayType::Hard, DayType::Deload]);
});

it('DOM_006 a mesocycle holds its sessions, slots and priorities', function () {
    $mesocycle = new Mesocycle(
        id: 'm-1', phase: PhaseType::MuscleGain, weeks: 5, sessions: [upperA()],
        musclePriorities: ['chest' => Priority::Emphasize, 'back' => Priority::Grow, 'calves' => Priority::Maintain],
    );

    expect(benchSlot()->exerciseName)->toBe('Bench press')
        ->and(benchSlot()->muscle)->toBe(MuscleGroup::Chest)
        ->and(benchSlot()->sets)->toBe(3)
        ->and(upperA()->slots)->toEqual([benchSlot()])
        ->and($mesocycle->weeks)->toBe(5)
        ->and($mesocycle->sessions)->toEqual([upperA()])
        ->and($mesocycle->musclePriorities)->toBe(['chest' => Priority::Emphasize, 'back' => Priority::Grow, 'calves' => Priority::Maintain])
        ->and($mesocycle->earlierWeeks)->toBe([])
        ->and($mesocycle->laterWeeks)->toBe([])
        ->and($mesocycle->lastDay)->toBeNull();
});

it('DOM_006 a resensitization mesocycle uses the resensitization column', function () {
    $resensitization = new Mesocycle(
        id: 'm-2', phase: PhaseType::Maintenance, weeks: 4, sessions: [upperA()], musclePriorities: [], isResensitization: true,
    );
    $balanced = new Mesocycle(id: 'm-3', phase: PhaseType::Maintenance, weeks: 4, sessions: [], musclePriorities: []);

    expect($resensitization->isResensitization)->toBeTrue()
        ->and($resensitization->trainingDefaultsColumn())->toBe(Column::MaintenanceResensitization)
        ->and($balanced->isResensitization)->toBeFalse();
});

it('SCH-039 a mesocycle week runs its earlier, later or full sessions', function () {
    $a = new Session(id: 'a', name: 'A', slots: []);
    $b = new Session(id: 'b', name: 'B', slots: []);
    $old = new Session(id: 'old', name: 'Old', slots: []);
    $mesocycle = new Mesocycle(
        id: 'm-4', phase: PhaseType::MuscleGain, weeks: 4, sessions: [$a, $b], musclePriorities: [],
        earlierWeeks: [[$old]], laterWeeks: [['b', 'missing', 'a']],
    );

    expect($mesocycle->sessionsInWeek(0))->toBe([$old])
        ->and($mesocycle->sessionsInWeek(1))->toBe([$b, $a])
        ->and($mesocycle->sessionsInWeek(2))->toBe([$a, $b]);
});

it('DOM_007 a meal holds its target', function () {
    $target = new MealTarget(kcal: 600, proteinG: 40, fatG: 15, carbsG: 70);
    $meal = new Meal(id: 'meal-1', name: 'Post-workout', target: $target);

    expect([$target->kcal, $target->proteinG, $target->fatG, $target->carbsG])->toBe([600.0, 40.0, 15.0, 70.0])
        ->and($meal->name)->toBe('Post-workout')
        ->and($meal->target)->toBe($target);
});

it('DOM_008 a workout holds its feedback, and a weigh-in its weight', function () {
    $feedback = new FeedbackAnswers(
        jointPain: ['slot-1' => JointPain::Low], soreness: Soreness::HealedJustOnTime, pump: Pump::Amazing, volume: VolumeFeedback::TooMuch,
    );
    $empty = new FeedbackAnswers;
    $workout = new Workout(id: 'w-1', date: modelDate(), session: upperA(), feedback: ['chest' => $feedback]);
    $weighIn = new WeighIn(id: 'wi-1', date: modelDate(), weightKg: 80.25);

    expect($feedback->jointPain)->toBe(['slot-1' => JointPain::Low])
        ->and($feedback->soreness)->toBe(Soreness::HealedJustOnTime)
        ->and($feedback->pump)->toBe(Pump::Amazing)
        ->and($feedback->volume)->toBe(VolumeFeedback::TooMuch)
        ->and([$empty->jointPain, $empty->soreness, $empty->pump, $empty->volume])->toBe([[], null, null, null])
        ->and($workout->session)->toEqual(upperA())
        ->and($workout->feedback)->toBe(['chest' => $feedback])
        ->and($weighIn->weightKg)->toBe(80.25)
        ->and($weighIn->date)->toEqual(modelDate());
});

it('DOM_009 has fourteen muscle groups in spec order', function () {
    expect(array_map(fn (MuscleGroup $muscle) => $muscle->value, MuscleGroup::cases()))->toBe([
        'chest', 'back', 'quads', 'hamstrings', 'glutes', 'sideDelts', 'rearDelts',
        'frontDelts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms',
    ])
        ->and(MuscleGroup::cases())->toHaveCount(14)
        ->and(MuscleGroup::SideDelts->title())->toBe('Side delts');
});

it('DOM_010 lists the priorities', function () {
    expect(Priority::cases())->toBe([Priority::Emphasize, Priority::Grow, Priority::Maintain]);
});

it('DOM_011 lists the phase types', function () {
    expect(PhaseType::cases())->toBe([PhaseType::FatLoss, PhaseType::MiniCut, PhaseType::Maintenance, PhaseType::MuscleGain]);
});

it('DOM_012 lists experience levels, sexes and body-fat buckets', function () {
    expect(ExperienceLevel::cases())->toBe([ExperienceLevel::Beginner, ExperienceLevel::Intermediate, ExperienceLevel::Advanced])
        ->and(Sex::cases())->toBe([Sex::Male, Sex::Female])
        ->and(BodyFatBucket::cases())->toHaveCount(4);
});

it('PLAN-008 a plan week keeps its own day count, and an empty week has no days', function () {
    $short = new PlanWeek(start: modelDate(), dayCount: 3);
    $empty = new PlanWeek(start: modelDate(), dayCount: 0);

    expect($short->days())->toHaveCount(3)
        ->and($short->end())->toEqual(modelDate()->setTime(0, 0)->modify('+2 days'))
        ->and($empty->days())->toBe([])
        ->and($empty->end())->toBeNull();
});

it('PLAN-008 a plan week across a daylight-saving change keeps its zone, one day per calendar date at local midnight', function () {
    $zone = new DateTimeZone('America/New_York');
    $week = new PlanWeek(start: new DateTimeImmutable('2026-03-08 12:00', $zone));

    expect(array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d H:i e'), $week->days()))->toBe([
        '2026-03-08 00:00 America/New_York',
        '2026-03-09 00:00 America/New_York',
        '2026-03-10 00:00 America/New_York',
        '2026-03-11 00:00 America/New_York',
        '2026-03-12 00:00 America/New_York',
        '2026-03-13 00:00 America/New_York',
        '2026-03-14 00:00 America/New_York',
    ]);
});

it('PLAN-008 the current review day is the last change chosen, even before its first day', function () {
    $later = modelDate()->modify('+14 days');
    $plan = new Plan(
        id: 'plan-1', start: modelDate(), reviewWeekday: 2, phases: [],
        reviewDayChanges: [new ReviewDayChange(from: modelDate(), weekday: 6), new ReviewDayChange(from: $later, weekday: 3)],
    );

    expect($plan->currentReviewWeekday())->toBe(3);
});
