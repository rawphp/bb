<?php

namespace App\Models;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\OnboardingTemplateDay;
use App\Domain\Onboarding\OnboardingTemplateSlot;
use App\Domain\Onboarding\OnboardingTraining;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\ScreeningItem;
use App\Models\Concerns\BelongsToUser;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup progress (ONB-002), ported from rp-clone's OnboardingDraft and OnboardingDraftStore: the step and every answer
 * so far, one row per user. `for()` is the store's load and never writes; the first answer saves the row and
 * onboarding_complete deletes it. `toDraft()` hands the answers to the rules in `App\Domain\Onboarding`, and
 * `fillFrom()` writes a changed `Draft` back.
 *
 * @property int $user_id
 * @property int $step
 * @property ?PhaseType $goal
 * @property ?bool $finished_diet_recently
 * @property string $name
 * @property ?Sex $sex
 * @property ?DateTimeInterface $birth_date
 * @property ?float $height_cm
 * @property ?float $weight_kg
 * @property WeightUnit $weight_unit
 * @property HeightUnit $height_unit
 * @property list<string> $screening
 * @property ?bool $uses_glp1
 * @property ?BodyFatBucket $body_fat_bucket
 * @property ?float $rate_percent_per_week
 * @property bool $custom_rate
 * @property ?DateTimeInterface $start_date
 * @property ?int $weeks
 * @property ?ExperienceLevel $experience
 * @property list<array{weekday: int, start_minutes: int, length_minutes: int}> $training_days
 * @property ?TrainingPlanKind $training_plan
 * @property ?int $template_id
 * @property list<array{weekday: int, min: int, max: int}> $step_ranges
 * @property int $eating_start_minutes
 * @property int $eating_end_minutes
 * @property int $meal_count
 * @property list<array{id: string, label: string, start_minutes: int, end_minutes: int, weekdays: list<int>}> $busy_periods
 * @property list<array{weekday: int, minutes: int}> $weigh_ins
 * @property ?int $review_weekday
 * @property bool $chooses_own_calories
 * @property ?int $own_daily_kcal
 */
#[Fillable([
    'step', 'goal', 'finished_diet_recently', 'name', 'sex', 'birth_date', 'height_cm', 'weight_kg', 'weight_unit',
    'height_unit', 'screening', 'uses_glp1', 'body_fat_bucket', 'rate_percent_per_week', 'custom_rate', 'start_date',
    'weeks', 'experience', 'training_days', 'training_plan', 'template_id', 'step_ranges', 'eating_start_minutes',
    'eating_end_minutes', 'meal_count', 'busy_periods', 'weigh_ins', 'review_weekday', 'chooses_own_calories',
    'own_daily_kcal',
])]
class OnboardingDraft extends Model
{
    use BelongsToUser;

    /** A new draft: step 1, kg and cm (ONB-004), the step 11 defaults and no answers. */
    protected $attributes = [
        'step' => 1,
        'name' => '',
        'weight_unit' => 'kg',
        'height_unit' => 'cm',
        'screening' => '[]',
        'custom_rate' => false,
        'training_days' => '[]',
        'step_ranges' => '[]',
        'eating_start_minutes' => 7 * 60,
        'eating_end_minutes' => 20 * 60,
        'meal_count' => 5,
        'busy_periods' => '[]',
        'weigh_ins' => '[]',
        'chooses_own_calories' => false,
    ];

    /** The user's draft, or an unsaved new one for a user with none. Reading never writes (ONB-002). */
    public static function for(User $user): self
    {
        return self::query()->where('user_id', $user->id)->first()
            ?? (new self)->forceFill(['user_id' => $user->id]);
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** The answers as the Onboarding rules read them. */
    public function toDraft(): Draft
    {
        $ticked = array_map(fn (string $item) => ScreeningItem::tryFrom($item), $this->screening);

        return new Draft(
            step: $this->step,
            goal: $this->goal,
            finishedDietRecently: $this->finished_diet_recently,
            name: $this->name,
            sex: $this->sex,
            birthDate: self::day($this->birth_date),
            heightCm: $this->height_cm,
            weightKg: $this->weight_kg,
            weightUnit: $this->weight_unit,
            heightUnit: $this->height_unit,
            screening: array_values(array_filter(
                ScreeningItem::cases(), fn (ScreeningItem $item) => in_array($item, $ticked, true))),
            usesGLP1: $this->uses_glp1,
            bodyFatBucket: $this->body_fat_bucket,
            ratePercentPerWeek: $this->rate_percent_per_week,
            customRate: $this->custom_rate,
            startDate: self::day($this->start_date),
            weeks: $this->weeks,
            experience: $this->experience,
            trainingDays: array_map(
                fn (array $day) => new TrainingDayChoice($day['weekday'], $day['start_minutes'], $day['length_minutes']),
                $this->training_days),
            trainingPlan: $this->training_plan,
            templateId: $this->template_id,
            stepRanges: array_map(
                fn (array $range) => new StepRangeChoice($range['weekday'], $range['min'], $range['max']),
                $this->step_ranges),
            eatingStartMinutes: $this->eating_start_minutes,
            eatingEndMinutes: $this->eating_end_minutes,
            mealCount: $this->meal_count,
            busyPeriods: array_map(
                fn (array $period) => new BusyPeriodChoice(
                    $period['id'], $period['label'], $period['start_minutes'], $period['end_minutes'], $period['weekdays']),
                $this->busy_periods),
            weighIns: array_map(fn (array $weighIn) => new WeighInChoice($weighIn['weekday'], $weighIn['minutes']), $this->weigh_ins),
            reviewWeekday: $this->review_weekday,
            choosesOwnCalories: $this->chooses_own_calories,
            ownDailyKcal: $this->own_daily_kcal,
        );
    }

    /** The answers written back onto the row, the inverse of `toDraft()`. Save it to store them. */
    public function fillFrom(Draft $draft): self
    {
        return $this->fill([
            'step' => $draft->step,
            'goal' => $draft->goal,
            'finished_diet_recently' => $draft->finishedDietRecently,
            'name' => $draft->name,
            'sex' => $draft->sex,
            'birth_date' => $draft->birthDate?->format('Y-m-d'),
            'height_cm' => $draft->heightCm,
            'weight_kg' => $draft->weightKg,
            'weight_unit' => $draft->weightUnit,
            'height_unit' => $draft->heightUnit,
            'screening' => array_map(fn (ScreeningItem $item) => $item->value, $draft->screening),
            'uses_glp1' => $draft->usesGLP1,
            'body_fat_bucket' => $draft->bodyFatBucket,
            'rate_percent_per_week' => $draft->ratePercentPerWeek,
            'custom_rate' => $draft->customRate,
            'start_date' => $draft->startDate?->format('Y-m-d'),
            'weeks' => $draft->weeks,
            'experience' => $draft->experience,
            'training_days' => array_map(fn (TrainingDayChoice $day) => [
                'weekday' => $day->weekday, 'start_minutes' => $day->startMinutes, 'length_minutes' => $day->lengthMinutes,
            ], $draft->trainingDays),
            'training_plan' => $draft->trainingPlan,
            'template_id' => $draft->templateId,
            'step_ranges' => array_map(fn (StepRangeChoice $range) => [
                'weekday' => $range->weekday, 'min' => $range->min, 'max' => $range->max,
            ], $draft->stepRanges),
            'eating_start_minutes' => $draft->eatingStartMinutes,
            'eating_end_minutes' => $draft->eatingEndMinutes,
            'meal_count' => $draft->mealCount,
            'busy_periods' => array_map(fn (BusyPeriodChoice $period) => [
                'id' => $period->id, 'label' => $period->label, 'start_minutes' => $period->startMinutes,
                'end_minutes' => $period->endMinutes, 'weekdays' => $period->weekdays,
            ], $draft->busyPeriods),
            'weigh_ins' => array_map(fn (WeighInChoice $weighIn) => [
                'weekday' => $weighIn->weekday, 'minutes' => $weighIn->minutes,
            ], $draft->weighIns),
            'review_weekday' => $draft->reviewWeekday,
            'chooses_own_calories' => $draft->choosesOwnCalories,
            'own_daily_kcal' => $draft->ownDailyKcal,
        ]);
    }

    /**
     * The library templates for the draft's day count, by name then id: the list step 9, the review and the start
     * all read, so the recommended template that stands in while nothing is picked is the same one everywhere.
     *
     * @return list<OnboardingTemplate>
     */
    public static function templates(Draft $draft): array
    {
        $days = count($draft->trainingDays);
        if (! in_array($days, OnboardingTraining::TEMPLATE_DAY_COUNTS, true)) {
            return [];
        }

        return Template::query()->where('days_per_week', $days)->with('slots.exercise')->orderBy('name')->orderBy('id')->get()
            ->map(self::onboardingTemplate(...))->values()->all();
    }

    /**
     * A library template as step 9 and the first mesocycle read it (ONB-012). Load `slots.exercise` first.
     */
    public static function onboardingTemplate(Template $template): OnboardingTemplate
    {
        $labels = $template->day_labels ?? [];

        return new OnboardingTemplate(
            id: $template->id,
            name: $template->name,
            split: $template->split,
            daysPerWeek: $template->days_per_week,
            sex: $template->sex,
            phaseFit: $template->phase_fit->values()->all(),
            priorities: $template->priorities ?? [],
            days: array_map(fn (int $day) => new OnboardingTemplateDay(
                $labels[$day - 1] ?? "Day {$day}",
                $template->slots
                    ->filter(fn (TemplateSlot $slot) => $slot->day === $day)
                    ->sortBy('position')
                    ->map(fn (TemplateSlot $slot) => new OnboardingTemplateSlot($slot->exercise->name, $slot->muscle_group, $slot->sets, $slot->exercise_id))
                    ->values()->all(),
            ), range(1, max(1, $template->days_per_week))),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'goal' => PhaseType::class,
            'finished_diet_recently' => 'boolean',
            'sex' => Sex::class,
            'birth_date' => 'date:Y-m-d',
            'height_cm' => 'float',
            'weight_kg' => 'float',
            'weight_unit' => WeightUnit::class,
            'height_unit' => HeightUnit::class,
            'screening' => 'array',
            'uses_glp1' => 'boolean',
            'body_fat_bucket' => BodyFatBucket::class,
            'rate_percent_per_week' => 'float',
            'custom_rate' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'weeks' => 'integer',
            'experience' => ExperienceLevel::class,
            'training_days' => 'array',
            'training_plan' => TrainingPlanKind::class,
            'template_id' => 'integer',
            'step_ranges' => 'array',
            'eating_start_minutes' => 'integer',
            'eating_end_minutes' => 'integer',
            'meal_count' => 'integer',
            'busy_periods' => 'array',
            'weigh_ins' => 'array',
            'review_weekday' => 'integer',
            'chooses_own_calories' => 'boolean',
            'own_daily_kcal' => 'integer',
        ];
    }

    /** A stored date as the calendar day the rules read: midnight in UTC. */
    private static function day(?DateTimeInterface $date): ?DateTimeImmutable
    {
        return $date === null ? null : new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
    }
}
