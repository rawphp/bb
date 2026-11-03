<?php

namespace App\Domain\Onboarding;

use App\Domain\Defaults\TBL001BodyFatBuckets;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use DateTimeImmutable;

/**
 * The review screen (ONB-018, ONB-019), the read members of rp-clone's OnboardingModel+Review: every section of the
 * plan in the order of ONB-018, each with the step its Edit link goes back to. A training-only plan leaves out what it
 * skipped (SAFE-003). The blocks are empty until the answers the plan needs are in.
 */
final class OnboardingReview
{
    /** ONB-019. Only a nutrition plan has meals to fine-tune. */
    public const NOTE = 'Exact meal times and amounts can be fine-tuned after you start.';

    public const BUILT_LATER = 'Built later';

    private readonly OnboardingHealth $health;

    private readonly OnboardingRateLength $rateLength;

    private readonly OnboardingTraining $training;

    private readonly OnboardingLifestyle $lifestyle;

    private readonly OnboardingCalories $calories;

    /**
     * @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it
     * @param  ?OnboardingCalories  $calories  step 13's state for the same answers, when the caller has built it already
     */
    public function __construct(
        private readonly Draft $draft,
        private readonly DateTimeImmutable $today,
        private readonly array $templates,
        ?OnboardingCalories $calories = null,
    ) {
        $this->health = new OnboardingHealth($draft, $today);
        $this->rateLength = new OnboardingRateLength($draft, $today);
        $this->training = new OnboardingTraining($draft, $templates);
        $this->lifestyle = new OnboardingLifestyle($draft);
        $this->calories = $calories ?? new OnboardingCalories($draft, $today, $templates);
    }

    /** ONB-019: "" in training-only mode. */
    public function note(): string
    {
        return $this->health->isTrainingOnly() ? '' : self::NOTE;
    }

    /** @return list<ReviewBlock> */
    public function blocks(): array
    {
        $count = 0;
        $inputs = OnboardingPlanInputs::from($this->draft, $this->today, $this->templates, function () use (&$count) {
            $count++;

            return "review-{$count}";
        });
        if ($inputs === null) {
            return [];
        }
        $nutrition = ! $this->health->isTrainingOnly();

        return array_values(array_filter([
            $this->goal(),
            $this->phase($inputs),
            $this->aboutYou($nutrition),
            $nutrition ? $this->steps() : null,
            $this->trainingDays(),
            $nutrition ? $this->meals() : null,
            $this->mesocycle(),
            $this->weighIns(),
            $nutrition ? $this->calories() : null,
        ]));
    }

    private function goal(): ReviewBlock
    {
        $rows = [new ReviewRow('Goal', $this->draft->goal?->title() ?? '')];
        if ($this->health->isTrainingOnly()) {
            $rows[] = new ReviewRow('Mode', 'Training only');
        }

        return new ReviewBlock('Goal', 1, true, $rows);
    }

    private function phase(OnboardingPlanInputs $inputs): ReviewBlock
    {
        $nutrition = ! $this->health->isTrainingOnly();
        $phase = $inputs->phase();
        if ($phase->end === null) {
            return new ReviewBlock('Phase', 6, $nutrition, []);
        }
        $weeks = PlanRules::weeks($phase, PlanWeekGrid::forPlan($inputs->plan))?->count() ?? $this->draft->weeks ?? 0;
        $last = OnboardingDates::addingDays($phase->end, -1);
        $rows = [
            new ReviewRow('Length', "{$weeks} weeks"),
            new ReviewRow('Dates', OnboardingText::dateText($phase->start).' to '.OnboardingText::dateText($last)),
        ];
        if ($nutrition) {
            $unit = $this->draft->weightUnit;
            if ($this->draft->weightKg !== null) {
                $rows[] = new ReviewRow('Start weight', OnboardingText::weightText($this->draft->weightKg, $unit));
            }
            $target = $this->rateLength->targetWeightKg();
            if ($target !== null) {
                $rows[] = new ReviewRow('Target weight', OnboardingText::weightText($target, $unit));
            }
            if ($this->draft->ratePercentPerWeek !== null) {
                $rows[] = new ReviewRow('Weekly rate', OnboardingText::rateText($this->draft->ratePercentPerWeek).' of body weight');
            }
        }

        return new ReviewBlock('Phase', 6, $nutrition, $rows);
    }

    private function aboutYou(bool $nutrition): ReviewBlock
    {
        $draft = $this->draft;
        $rows = [new ReviewRow('Name', trim($draft->name))];
        if ($draft->sex !== null) {
            $rows[] = new ReviewRow('Sex', ucfirst($draft->sex->value));
        }
        if ($draft->birthDate !== null) {
            $rows[] = new ReviewRow('Date of birth', OnboardingText::dateText($draft->birthDate));
        }
        if ($draft->heightCm !== null) {
            $rows[] = new ReviewRow('Height', OnboardingText::heightText($draft->heightCm, $draft->heightUnit));
        }
        if ($draft->weightKg !== null) {
            $rows[] = new ReviewRow('Weight', OnboardingText::weightText($draft->weightKg, $draft->weightUnit));
        }
        if ($nutrition && $draft->sex !== null && $draft->bodyFatBucket !== null) {
            foreach (TBL001BodyFatBuckets::rows($draft->sex) as $row) {
                if ($row->bucket === $draft->bodyFatBucket) {
                    $rows[] = new ReviewRow('Body fat', $row->label);
                }
            }
        }

        return new ReviewBlock('About you', 2, true, $rows);
    }

    /** Runs of neighbouring days with the same range share a row. */
    private function steps(): ReviewBlock
    {
        $runs = [];
        foreach ($this->lifestyle->stepRanges() as $range) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['min'] === $range->min && $runs[$last]['max'] === $range->max) {
                $runs[$last]['weekdays'][] = $range->weekday;
            } else {
                $runs[] = ['min' => $range->min, 'max' => $range->max, 'weekdays' => [$range->weekday]];
            }
        }

        return new ReviewBlock('Steps', 10, true, array_map(fn (array $run) => new ReviewRow(
            OnboardingText::weekdaysText($run['weekdays']),
            OnboardingText::numberText($run['min']).' to '.OnboardingText::numberText($run['max']),
        ), $runs));
    }

    private function trainingDays(): ReviewBlock
    {
        return new ReviewBlock('Training days', 8, true, array_map(fn (TrainingDayChoice $day) => new ReviewRow(
            OnboardingText::weekdayName($day->weekday),
            OnboardingText::timeText($day->startMinutes)." for {$day->lengthMinutes} min",
        ), $this->draft->trainingDays));
    }

    private function meals(): ReviewBlock
    {
        $rows = [
            new ReviewRow('Eating window', OnboardingText::timeText($this->draft->eatingStartMinutes)
                .' to '.OnboardingText::timeText($this->draft->eatingEndMinutes)),
            new ReviewRow('Meals a day', (string) $this->draft->mealCount),
        ];
        foreach ($this->draft->busyPeriods as $period) {
            $rows[] = new ReviewRow($period->label, OnboardingText::timeText($period->startMinutes).' to '
                .OnboardingText::timeText($period->endMinutes).', '.OnboardingText::weekdaysText($period->weekdays));
        }

        return new ReviewBlock('Meals', 11, true, $rows);
    }

    private function mesocycle(): ReviewBlock
    {
        $weeks = $this->training->mesocycleWeeks() ?? 0;

        return new ReviewBlock('First mesocycle', 9, true, [
            new ReviewRow('Name', $this->training->selectedTemplate()->name ?? self::BUILT_LATER),
            new ReviewRow('Length', "{$weeks} weeks"),
            new ReviewRow('Days', OnboardingText::weekdaysText(array_map(fn (TrainingDayChoice $day) => $day->weekday, $this->draft->trainingDays))),
            new ReviewRow('Deload week', "Week {$weeks}"),
        ]);
    }

    private function weighIns(): ReviewBlock
    {
        $rows = array_map(fn (WeighInChoice $weighIn) => new ReviewRow(
            OnboardingText::weekdayName($weighIn->weekday), OnboardingText::timeText($weighIn->minutes),
        ), $this->draft->weighIns);
        $rows[] = new ReviewRow('Review day', OnboardingText::weekdayFullName($this->lifestyle->reviewWeekday()));

        return new ReviewBlock('Weigh-ins', 12, true, $rows);
    }

    /** "Selected" next to "Recommended" (ONB-018): one row marked both when the recommendation is the choice. */
    private function calories(): ReviewBlock
    {
        $rows = [];
        $recommended = $this->calories->recommended();
        if ($recommended !== null) {
            $own = $this->draft->choosesOwnCalories;
            $rows[] = new ReviewRow('RP recommendation', OnboardingText::kcalText($recommended->averageKcal),
                $own ? ['Recommended'] : ['Recommended', 'Selected']);
            $selected = $this->calories->selected();
            if ($own && $selected !== null) {
                $rows[] = new ReviewRow('Your choice', OnboardingText::kcalText($selected->averageKcal), ['Selected']);
            }
        }

        return new ReviewBlock('Calories', 13, true, $rows);
    }
}
