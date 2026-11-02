<?php

namespace App\Domain\Onboarding;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\Phase;
use App\Domain\Model\Priority;
use App\Domain\Placement\ClockTime;
use App\Domain\Safety\ScreeningItem;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Enums\MesocycleRoute;
use Closure;
use DateTimeImmutable;
use DomainException;

/**
 * "Start my plan" (ONB-020), rp-clone's OnboardingModel+Start: what the answers save, as values. Every visible step must
 * be able to continue; the plan's first week is built by the rules, moved by a chosen calorie average (ONB-017); a
 * start date that has passed moves to tomorrow and keeps its length. A training-only setup (ONB-005, SAFE-003) saves
 * the defaults of the steps it skipped and no day targets. `App\Actions\Onboarding\StartPlan` saves it.
 *
 * Weekdays are ISO (1 = Monday).
 */
final readonly class OnboardingStart
{
    public const INCOMPLETE_MESSAGE = 'Some answers are missing. Go back through the steps and check each one.';

    /** The name of a first mesocycle that has no template to take one from. */
    public const DRAFT_MESOCYCLE_NAME = 'First mesocycle';

    /**
     * @param  Draft  $draft  the answers as saved: the start moved to tomorrow at the earliest and, for a training-only
     *                        setup, the steps it skipped (4, 5, 10 and 11) at their defaults
     * @param  OnboardingPlanInputs  $inputs  the profile, the plan, the first mesocycle and its template and weekdays
     * @param  Phase  $phase  the first phase, with its end
     * @param  list<ScreeningItem>  $screening  the items that applied, ticked or measured, in the spec's order (SAFE-001)
     * @param  list<StepRangeChoice>  $stepRanges  every weekday's range, the defaults standing in (ONB-013)
     * @param  ?array<string, Priority>  $musclePriorities  keyed by MuscleGroup value; null for a draft mesocycle, which
     *                                                      the build finishes
     * @param  list<WeekDay>  $targetDays  week one's days that have targets; none for a training-only setup
     * @param  list<array{weekdays: list<int>, time: string}>  $weighInSchedule
     */
    public function __construct(
        public Draft $draft,
        public OnboardingPlanInputs $inputs,
        public Phase $phase,
        public bool $trainingOnly,
        public array $screening,
        public array $stepRanges,
        public int $reviewWeekday,
        public string $mesocycleName,
        public MesocycleRoute $route,
        public ?array $musclePriorities,
        public BuiltWeek $week,
        public array $targetDays,
        public array $weighInSchedule,
    ) {}

    /**
     * @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it
     * @param  Closure(): string  $newId  an id for the phase, the mesocycle, each session, slot and meal
     *
     * @throws DomainException in the words of the step that cannot continue, or of the rules that refuse the week
     */
    public static function from(Draft $draft, DateTimeImmutable $today, array $templates, Closure $newId): self
    {
        $draft = self::startingTomorrowAtTheEarliest($draft, $today);
        // One calorie state for the step check and the start: the week is built once (it does not depend on the step).
        $calories = new OnboardingCalories($draft, $today, $templates);
        self::refuseUnlessEveryStepCanContinue($draft, $today, $templates, $calories);
        $inputs = OnboardingPlanInputs::from($draft, $today, $templates, $newId) ?? throw new DomainException(self::INCOMPLETE_MESSAGE);
        $trainingOnly = $inputs->schedule->nutrition === null;

        // ONB-017: a chosen average moves week one by its difference from the recommendation.
        $change = 0.0;
        if (! $trainingOnly) {
            $recommended = $calories->recommended();
            if ($recommended === null || ! $calories->isReady()) {
                throw new DomainException($calories->message() ?? self::INCOMPLETE_MESSAGE);
            }
            if ($draft->choosesOwnCalories && $draft->ownDailyKcal !== null) {
                $change = $draft->ownDailyKcal - $recommended->averageKcal;
            }
        }
        try {
            $built = $inputs->buildWeek(0, $change, $newId);
        } catch (WeekBuildRefusal $refusal) {
            throw new DomainException($calories->messageFor($refusal));
        }

        $phase = $inputs->phase();
        if ($phase->end === null) {
            throw new DomainException(self::INCOMPLETE_MESSAGE);
        }
        // SAFE-001: the items that applied, ticked or measured, in the spec's order.
        $applied = [...$draft->screening, ...(new OnboardingHealth($draft, $today))->measuredItems()];
        $saved = $trainingOnly ? self::withSkippedStepsAtTheirDefaults($draft) : $draft;
        $template = $inputs->template;

        return new self(
            draft: $saved,
            inputs: $inputs,
            phase: $phase,
            trainingOnly: $trainingOnly,
            screening: array_values(array_filter(ScreeningItem::cases(), fn (ScreeningItem $item) => in_array($item, $applied, true))),
            stepRanges: (new OnboardingLifestyle($saved))->stepRanges(),
            reviewWeekday: (new OnboardingLifestyle($draft))->reviewWeekday(),
            mesocycleName: $template->name ?? self::DRAFT_MESOCYCLE_NAME,
            route: match (true) {
                $template !== null => MesocycleRoute::Template,
                $draft->trainingPlan === TrainingPlanKind::MesoBuilder => MesocycleRoute::Builder,
                default => MesocycleRoute::Scratch,
            },
            musclePriorities: self::musclePriorities($inputs),
            week: $built,
            targetDays: $trainingOnly ? [] : array_values(array_filter($built->days, fn (WeekDay $day) => $day->target !== null)),
            weighInSchedule: self::weighInSchedule($draft->weighIns),
        );
    }

    /**
     * Every day of plan week 0, targets or not, Y-m-d.
     *
     * @return list<string>
     */
    public function weekDates(): array
    {
        return array_map(fn (WeekDay $day) => $day->day->date->format('Y-m-d'), $this->week->days);
    }

    /** ONB-020: the plan starts tomorrow at the earliest. An earlier start moves to tomorrow; the length in weeks stays. */
    public static function startingTomorrowAtTheEarliest(Draft $draft, DateTimeImmutable $today): Draft
    {
        $tomorrow = OnboardingDates::tomorrow($today);

        return $draft->startDate !== null && OnboardingDates::day($draft->startDate) < $tomorrow
            ? $draft->with(startDate: $tomorrow)
            : $draft;
    }

    /**
     * WT-001: weekdays that share a time share an entry, in the order of their first weekday.
     *
     * @param  list<WeighInChoice>  $weighIns
     * @return list<array{weekdays: list<int>, time: string}>
     */
    public static function weighInSchedule(array $weighIns): array
    {
        $byTime = [];
        foreach ($weighIns as $weighIn) {
            $byTime[$weighIn->minutes][] = $weighIn->weekday;
        }
        $entries = [];
        foreach ($byTime as $minutes => $weekdays) {
            sort($weekdays);
            $entries[] = ['weekdays' => $weekdays, 'time' => (string) new ClockTime($minutes)];
        }
        usort($entries, fn (array $a, array $b) => [$a['weekdays'][0], $a['time']] <=> [$b['weekdays'][0], $b['time']]);

        return $entries;
    }

    /** @param  list<OnboardingTemplate>  $templates */
    private static function refuseUnlessEveryStepCanContinue(Draft $draft, DateTimeImmutable $today, array $templates, OnboardingCalories $calories): void
    {
        $steps = new OnboardingSteps($draft->with(step: OnboardingSteps::REVIEW_STEP), $today, $templates, $calories);
        foreach ($steps->visibleSteps() as $step) {
            if (! $steps->canContinueOn($step)) {
                throw new DomainException($steps->continueHintOn($step) ?? self::INCOMPLETE_MESSAGE);
            }
        }
    }

    /** ONB-005: a training-only user skipped steps 4, 5, 10 and 11, so those are saved as their defaults. */
    private static function withSkippedStepsAtTheirDefaults(Draft $draft): Draft
    {
        $defaults = new Draft;

        return $draft->with(
            usesGLP1: null, bodyFatBucket: null, stepRanges: $defaults->stepRanges,
            eatingStartMinutes: $defaults->eatingStartMinutes, eatingEndMinutes: $defaults->eatingEndMinutes,
            mealCount: $defaults->mealCount, busyPeriods: $defaults->busyPeriods,
        );
    }

    /**
     * MESO-012: the template's priorities; MESO-003: the phase's preset when it has none. A draft leaves them to the
     * build.
     *
     * @return ?array<string, Priority>
     */
    private static function musclePriorities(OnboardingPlanInputs $inputs): ?array
    {
        if ($inputs->template === null) {
            return null;
        }
        $priorities = $inputs->mesocycle->musclePriorities;

        return $priorities === [] ? MesoBuilder::defaultPriorities(Column::for($inputs->mesocycle->phase, false)) : $priorities;
    }
}
