<?php

namespace App\Domain\Onboarding;

use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Model\PlanWeek;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekBuildRefusalReason;
use App\Domain\WeekBuilder\WeekDay;
use DateTimeImmutable;

/**
 * Step 13, calories (ONB-017), the calorie state of rp-clone's OnboardingModel+Plan: "Our recommendation" (the
 * default) or "Choose my own average daily calories". The recommendation shows its daily average and the split of a
 * plan week; a short first week would split only a few days, so it shows the first full week then (the targets are
 * the same weekday by weekday). A chosen average moves every day by its difference from the recommendation.
 */
final class OnboardingCalories
{
    public const OWN_CALORIES_MESSAGE = 'Enter your average daily calories to continue.';

    public const MAXIMUM_OWN_DAILY_KCAL = 10_000;

    /** @var ?array{recommended: ?CalorieWeek, selected: ?CalorieWeek, message: ?string} */
    private ?array $state = null;

    /** @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it */
    public function __construct(
        private readonly Draft $draft,
        private readonly DateTimeImmutable $today,
        private readonly array $templates,
    ) {}

    /** The recommended week; null for training-only, before the answers are in, or when the rules refuse it. */
    public function recommended(): ?CalorieWeek
    {
        return $this->state()['recommended'];
    }

    /** What will be saved: the recommendation, or the user's own average. Null while that is refused or empty. */
    public function selected(): ?CalorieWeek
    {
        return $this->state()['selected'];
    }

    /** Why step 13 cannot continue; null when it can, or when there is nothing to say. */
    public function message(): ?string
    {
        return $this->state()['message'];
    }

    public function isReady(): bool
    {
        return $this->selected() !== null && $this->message() === null;
    }

    /** What to tell the user when the rules refuse the week (SAFE-011, SAFE-012, SCH-041). */
    public function messageFor(WeekBuildRefusal $refusal): string
    {
        return match ($refusal->reason) {
            WeekBuildRefusalReason::NoPhase => 'The plan has no phase for its first week. Go back to step 6 and check the dates.',
            WeekBuildRefusalReason::Targets => match ($refusal->targets?->reason) {
                TargetRefusalReason::BmiAtOrBelowFloor => 'Your BMI is too low for calories below maintenance. Choose Maintenance or Muscle gain on step 1.',
                default => $this->draft->choosesOwnCalories
                    ? 'That is too few calories to fit your protein, fat and carbs. Choose a higher average.'
                    : 'These targets would leave too few carbs. Choose a slower rate or a shorter phase on step 6.',
            },
            WeekBuildRefusalReason::MealsDoNotFit => "Your eating window can't hold {$this->draft->mealCount} meals around your busy periods and workouts. "
                .'Widen it, or have fewer meals, on step 11.',
            WeekBuildRefusalReason::Move => "Those calories can't be moved between days.",
        };
    }

    /** @return array{recommended: ?CalorieWeek, selected: ?CalorieWeek, message: ?string} */
    private function state(): array
    {
        return $this->state ??= $this->compute();
    }

    /** @return array{recommended: ?CalorieWeek, selected: ?CalorieWeek, message: ?string} */
    private function compute(): array
    {
        $none = ['recommended' => null, 'selected' => null, 'message' => null];
        $inputs = OnboardingPlanInputs::from($this->draft, $this->today, $this->templates, self::previewIds());
        if ($inputs === null || $inputs->schedule->nutrition === null) {
            return $none;
        }
        $week = PlanWeekGrid::forPlan($inputs->plan)->lengthInDays(0) === PlanWeek::LENGTH_IN_DAYS ? 0 : 1;

        try {
            $recommended = self::calorieWeek($inputs->buildWeek($week));
        } catch (WeekBuildRefusal $refusal) {
            return [...$none, 'message' => $this->messageFor($refusal)];
        }
        if (! $this->draft->choosesOwnCalories) {
            return [...$none, 'recommended' => $recommended, 'selected' => $recommended];
        }
        $own = $this->draft->ownDailyKcal;
        if ($own === null || $own <= 0) {
            return [...$none, 'recommended' => $recommended, 'message' => self::OWN_CALORIES_MESSAGE];
        }
        try {
            $selected = self::calorieWeek($inputs->buildWeek($week, $own - $recommended->averageKcal));
        } catch (WeekBuildRefusal $refusal) {
            return [...$none, 'recommended' => $recommended, 'message' => $this->messageFor($refusal)];
        }

        return [...$none, 'recommended' => $recommended, 'selected' => $selected];
    }

    private static function calorieWeek(BuiltWeek $built): CalorieWeek
    {
        return new CalorieWeek(array_map(
            fn (WeekDay $day) => new CalorieDay(OnboardingText::weekdayName((int) $day->day->date->format('N')), $day->target->kcal ?? 0.0),
            $built->days,
        ));
    }

    /** Ids for a plan that is only looked at. */
    private static function previewIds(): \Closure
    {
        $count = 0;

        return function () use (&$count) {
            $count++;

            return "preview-{$count}";
        };
    }
}
