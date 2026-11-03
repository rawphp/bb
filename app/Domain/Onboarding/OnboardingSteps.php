<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\PhaseType;
use App\Domain\Safety\EligibilityMode;
use App\Domain\Safety\Screening;
use DateTimeImmutable;

/**
 * The step sequence (ONB-002 to ONB-018, UI-007), from rp-clone's OnboardingModel: the 13 steps and the review, the
 * training-only skips (ONB-005), the counter, the button title, whether a step can continue and why not, and the
 * target of Next, Back and an Edit link. The targets are only named here; the write moves the draft.
 */
final class OnboardingSteps
{
    public const STEP_COUNT = 13;

    /** The review (ONB-018) comes after step 13. It is not counted in "Step n of 13". */
    public const REVIEW_STEP = 14;

    private const KEYS = [
        1 => 'goal', 2 => 'about_you', 3 => 'screening', 4 => 'glp1', 5 => 'body_fat', 6 => 'rate_length',
        7 => 'experience', 8 => 'training_days', 9 => 'training_plan', 10 => 'steps', 11 => 'meals', 12 => 'weigh_ins',
        13 => 'calories', 14 => 'review',
    ];

    private const TITLES = [
        1 => "What's your goal?", 2 => 'About you', 3 => 'Health check', 4 => 'Weight-loss drugs', 5 => 'Body fat',
        6 => 'Rate and length', 7 => 'Training experience', 8 => 'Training days', 9 => 'Training plan',
        10 => 'Daily steps', 11 => 'Meals', 12 => 'Weigh-ins', 13 => 'Calories', 14 => 'Review your plan',
    ];

    private const HINTS = [
        1 => 'Pick a goal and answer the question to continue.',
        2 => 'Fill in every field to continue.',
        4 => 'Answer the question to continue.',
        5 => 'Pick a photo to continue.',
        6 => 'Choose a rate and a length to continue.',
        7 => 'Pick your training experience to continue.',
        8 => 'Pick at least one training day to continue.',
        9 => 'Pick a training plan to continue.',
        11 => OnboardingLifestyle::MEALS_DO_NOT_FIT_HINT,
        12 => OnboardingLifestyle::TOO_FEW_WEIGH_INS,
    ];

    private readonly OnboardingHealth $health;

    private ?OnboardingCalories $calories = null;

    /**
     * @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it
     * @param  ?OnboardingCalories  $calories  step 13's state for the same answers, when the caller has built it already,
     *                                         so the week is built once; it does not depend on the current step
     */
    public function __construct(
        private readonly Draft $draft,
        private readonly DateTimeImmutable $today,
        private readonly array $templates,
        ?OnboardingCalories $calories = null,
    ) {
        $this->health = new OnboardingHealth($draft, $today);
        $this->calories = $calories;
    }

    public static function keyOf(int $step): string
    {
        return self::KEYS[$step] ?? self::KEYS[1];
    }

    public static function titleOf(int $step): string
    {
        return self::TITLES[$step] ?? self::TITLES[1];
    }

    public function key(): string
    {
        return self::keyOf($this->draft->step);
    }

    public function title(): string
    {
        return self::titleOf($this->draft->step);
    }

    /**
     * ONB-005: once screening is passed training-only, the nutrition steps are not shown. The counter and the moves
     * all read this one list.
     *
     * @return list<int>
     */
    public function visibleSteps(): array
    {
        return $this->steps($this->draft->step > 3 && $this->health->isTrainingOnly());
    }

    /** A step that is not shown counts as the next one that is. The review counts as the last step. */
    public function position(): StepPosition
    {
        $steps = array_values(array_filter($this->visibleSteps(), fn (int $step) => $step !== self::REVIEW_STEP));
        $index = array_search($this->draft->step, $steps, true);
        if ($index === false) {
            $index = array_find_key($steps, fn (int $step) => $step > $this->draft->step) ?? count($steps) - 1;
        }

        return new StepPosition($index + 1, count($steps), $this->draft->step === self::REVIEW_STEP);
    }

    public function nextTitle(): string
    {
        return match ($this->draft->step) {
            6 => 'Continue',
            self::REVIEW_STEP => 'Start my plan',
            default => 'Next',
        };
    }

    public function canGoBack(): bool
    {
        return $this->position()->canGoBack();
    }

    public function canContinue(): bool
    {
        return $this->canContinueOn($this->draft->step);
    }

    /** Why Next does nothing yet; null when it works. */
    public function continueHint(): ?string
    {
        return $this->continueHintOn($this->draft->step);
    }

    /** Whether a step's answers let setup go on from it, whichever step the draft is on. */
    public function canContinueOn(int $step): bool
    {
        $draft = $this->draft;

        return match ($step) {
            1 => $draft->goal !== null && $this->health->isGoalAllowed($draft->goal) && $draft->finishedDietRecently !== null,
            2 => $this->health->hasAboutYou(),
            3, 10, self::REVIEW_STEP => true,
            4 => $draft->usesGLP1 !== null,
            5 => $draft->bodyFatBucket !== null,
            6 => $draft->ratePercentPerWeek !== null && $draft->startDate !== null && $draft->weeks !== null,
            7 => $draft->experience !== null,
            8 => $draft->trainingDays !== [],
            9 => (new OnboardingTraining($draft, $this->templates))->trainingPlanKind() !== null,
            11 => (new OnboardingLifestyle($draft))->mealsFit(),
            12 => (new OnboardingLifestyle($draft))->hasEnoughWeighIns(),
            13 => $this->calories()->isReady(),
            default => false,
        };
    }

    public function continueHintOn(int $step): ?string
    {
        if ($this->canContinueOn($step)) {
            return null;
        }

        return $step === 13 ? $this->calories()->message() : self::HINTS[$step] ?? null;
    }

    /**
     * Where Next leads; null when it does nothing (the step cannot continue, or this is the review). On step 3 the
     * screening decides (ONB-005): Fat loss while trying to conceive goes back to step 1 with the goal cleared, and
     * a training-only answer skips the nutrition steps.
     */
    public function next(): ?StepMove
    {
        $step = $this->draft->step;
        if ($step === self::REVIEW_STEP || ! $this->canContinue()) {
            return null;
        }
        if ($step === 3) {
            if ($this->health->eligibility()->mode === EligibilityMode::FatLossBlocked && $this->draft->goal === PhaseType::FatLoss) {
                return new StepMove(1, clearsGoal: true);
            }
            $target = self::move(3, $this->steps($this->health->isTrainingOnly()), 1);
        } else {
            $target = self::move($step, $this->visibleSteps(), 1);
        }

        return $target === null ? null : new StepMove($target);
    }

    /** Where Back leads; null on the first step. */
    public function back(): ?int
    {
        return self::move($this->draft->step, $this->visibleSteps(), -1);
    }

    /** ONB-018: an Edit link goes back to a step that is shown. Next then walks forward and every answer is kept. */
    public function edit(int $step): ?int
    {
        return $step < self::REVIEW_STEP && in_array($step, $this->visibleSteps(), true) ? $step : null;
    }

    /** @return list<int> */
    private function steps(bool $trainingOnly): array
    {
        $all = range(1, self::STEP_COUNT);
        $shown = $trainingOnly ? array_values(array_diff($all, Screening::NUTRITION_SETUP_STEPS)) : $all;

        return [...$shown, self::REVIEW_STEP];
    }

    /**
     * The step `$offset` away in `$steps`. A resumed step that is no longer shown goes to the nearest one that is.
     *
     * @param  list<int>  $steps
     */
    private static function move(int $from, array $steps, int $offset): ?int
    {
        $index = array_search($from, $steps, true);
        if ($index !== false) {
            return $steps[$index + $offset] ?? null;
        }

        return $offset > 0
            ? array_find($steps, fn (int $step) => $step > $from)
            : array_find(array_reverse($steps), fn (int $step) => $step < $from);
    }

    private function calories(): OnboardingCalories
    {
        return $this->calories ??= new OnboardingCalories($this->draft, $this->today, $this->templates);
    }
}
