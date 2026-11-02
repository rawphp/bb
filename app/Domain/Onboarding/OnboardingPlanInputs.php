<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase;
use App\Domain\Model\Plan;
use App\Domain\Model\Priority;
use App\Domain\Model\Profile;
use App\Domain\Model\Session;
use App\Domain\Model\StepPlanDay;
use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekBuilder;
use App\Domain\WeekBuilder\WeekSchedule;
use Closure;
use DateTimeImmutable;

/**
 * The plan the answers describe, as the rules take it (rp-clone OnboardingModel+Plan's planInputs): what the week
 * builder needs for step 13 (ONB-017), and what onboarding_complete saves (ONB-020).
 *
 * The answers count weekdays ISO (1 = Monday); the plan, its grid and the training days count 1 = Sunday, and the
 * step plan ISO again. This class is where they meet.
 */
final readonly class OnboardingPlanInputs
{
    /**
     * @param  ?OnboardingTemplate  $template  the template the mesocycle's sessions come from; null to build later
     * @param  list<int>  $sessionWeekdays  the ISO weekday of each session, in session order (plan-week order from the
     *                                      review day)
     */
    public function __construct(
        public Profile $profile,
        public Plan $plan,
        public Mesocycle $mesocycle,
        public WeekSchedule $schedule,
        public ?OnboardingTemplate $template,
        public array $sessionWeekdays,
    ) {}

    /**
     * Null until the answers the plan needs are in. A training-only plan (SAFE-003) needs no nutrition answers: it has
     * no nutrition schedule and its phase is one mesocycle long with no rate (PLAN-007).
     *
     * @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it
     * @param  Closure(): string  $newId  an id for the plan, the phase, the mesocycle, each session and each slot
     */
    public static function from(Draft $draft, DateTimeImmutable $today, array $templates, Closure $newId): ?self
    {
        $goal = $draft->goal;
        $sex = $draft->sex;
        $experience = $draft->experience;
        $start = $draft->startDate;
        if ($goal === null || $sex === null || $draft->birthDate === null || $draft->heightCm === null
            || $draft->weightKg === null || $experience === null || $start === null || $draft->trainingDays === []) {
            return null;
        }
        $trainingOnly = (new OnboardingHealth($draft, $today))->isTrainingOnly();
        if (! $trainingOnly && ($draft->bodyFatBucket === null || $draft->usesGLP1 === null
            || $draft->ratePercentPerWeek === null || $draft->weeks === null)) {
            return null;
        }

        $lifestyle = new OnboardingLifestyle($draft);
        $reviewIso = $lifestyle->reviewWeekday();
        $start = OnboardingDates::day($start);
        $grid = new PlanWeekGrid($start, self::calendarWeekday($reviewIso));
        $mesocycleWeeks = TBL020MesocycleLength::row($experience)->defaultWeeks;

        // PLAN-008: a phase of N weeks fills N plan weeks from the first, which is short when the plan starts off the
        // review day.
        $phase = $trainingOnly
            ? PlanRules::trainingOnlyPhase($newId(), $goal, 0, $experience, $grid)
            : new Phase($newId(), $goal, $grid->start(0), $grid->start((int) $draft->weeks), (float) $draft->ratePercentPerWeek);
        $plan = new Plan($newId(), $start, self::calendarWeekday($reviewIso), [$phase]);

        $template = (new OnboardingTraining($draft, $templates))->selectedTemplate();
        $mesocycle = new Mesocycle(
            id: $newId(), phase: $goal, weeks: $mesocycleWeeks,
            sessions: self::sessions($template, count($draft->trainingDays), $newId),
            musclePriorities: self::priorities($template),
        );

        $ordered = array_map(fn (TrainingDayChoice $day) => $day->weekday, $draft->trainingDays);
        usort($ordered, fn (int $a, int $b) => ($a - $reviewIso + 7) % 7 <=> ($b - $reviewIso + 7) % 7);

        $trainingDays = array_map(fn (TrainingDayChoice $day) => new TrainingDay(
            self::calendarWeekday($day->weekday), new WorkoutTime(new ClockTime($day->startMinutes), $day->lengthMinutes),
        ), $draft->trainingDays);
        $nutrition = $trainingOnly ? null : new NutritionSchedule(
            steps: array_map(fn (StepRangeChoice $range) => new StepPlanDay($range->weekday, $range->min, $range->max), $lifestyle->stepRanges()),
            eatingWindow: $lifestyle->eatingWindow(),
            mealsPerDay: $draft->mealCount,
            busy: $lifestyle->busyRanges(),
        );

        $profile = new Profile(
            name: trim($draft->name), sex: $sex, birthDate: $draft->birthDate, heightCm: $draft->heightCm,
            weightKg: $draft->weightKg, weightUnit: $draft->weightUnit, heightUnit: $draft->heightUnit,
            bodyFatBucket: $draft->bodyFatBucket ?? BodyFatBucket::Low, usesGLP1: $draft->usesGLP1 ?? false,
            experience: $experience,
        );

        return new self($profile, $plan, $mesocycle, new WeekSchedule($trainingDays, $nutrition), $template, $ordered);
    }

    public function phase(): Phase
    {
        return $this->plan->phases[0];
    }

    /**
     * A plan week: 0 is the first, which is short when the plan starts off the review day (PLAN-008).
     *
     * @param  float  $calorieChange  ONB-017: a chosen average moves every day by its difference from the recommendation
     * @param  ?Closure(): string  $newId  an id for each meal the week places
     *
     * @throws WeekBuildRefusal
     */
    public function buildWeek(int $week, float $calorieChange = 0, ?Closure $newId = null): BuiltWeek
    {
        $meals = 0;

        return WeekBuilder::build(
            plan: $this->plan, profile: $this->profile, mesocycle: $this->mesocycle, mesocycleStartWeek: 0,
            week: $week, schedule: $this->schedule,
            newId: $newId ?? function () use (&$meals) {
                $meals++;

                return "meal-{$meals}";
            },
            calorieChangePerDay: $calorieChange,
        );
    }

    /** ISO (1 = Monday) to the plan's weekday (1 = Sunday). */
    public static function calendarWeekday(int $iso): int
    {
        return $iso % 7 + 1;
    }

    /**
     * A template's days as sessions; "build later" gets one empty session per training day.
     *
     * @param  Closure(): string  $newId
     * @return list<Session>
     */
    private static function sessions(?OnboardingTemplate $template, int $trainingDayCount, Closure $newId): array
    {
        if ($template === null) {
            return array_map(fn (int $index) => new Session($newId(), "Workout {$index}", []), range(1, $trainingDayCount));
        }

        return array_map(function (OnboardingTemplateDay $day) use ($newId) {
            $id = $newId();

            return new Session($id, $day->label, array_map(
                fn (OnboardingTemplateSlot $slot) => new ExerciseSlot($newId(), $slot->exerciseName, $slot->muscle, $slot->sets),
                $day->slots,
            ));
        }, $template->days);
    }

    /** @return array<string, Priority> keyed by MuscleGroup value */
    private static function priorities(?OnboardingTemplate $template): array
    {
        $priorities = [];
        foreach ($template === null ? [] : $template->priorities as $muscle => $priority) {
            $group = MuscleGroup::tryFrom($muscle);
            $level = Priority::tryFrom($priority);
            if ($group !== null && $level !== null) {
                $priorities[$group->value] = $level;
            }
        }

        return $priorities;
    }
}
