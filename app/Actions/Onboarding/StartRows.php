<?php

namespace App\Actions\Onboarding;

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\OnboardingStart;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Placement\ClockTime;
use App\Domain\Safety\ScreeningItem;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use Closure;

/**
 * The rows "Start my plan" saves ({@see StartPlan}), from what the answers describe ({@see OnboardingStart}): the
 * columns, the stored json shapes and the profile's storage defaults. Times are "HH:MM"; dates are Y-m-d.
 */
final class StartRows
{
    /** @return array<string, mixed> the profiles row */
    public static function profile(OnboardingStart $start): array
    {
        $draft = $start->draft;
        $profile = $start->inputs->profile;

        return [
            'name' => $profile->name,
            'sex' => $profile->sex->value,
            'birth_date' => $profile->birthDate->format('Y-m-d'),
            'height_cm' => $profile->heightCm,
            'weight_kg' => $profile->weightKg,
            'body_fat_bucket' => $draft->bodyFatBucket?->value,
            'uses_glp1' => $draft->usesGLP1,
            'experience' => $profile->experience->value,
            'weight_unit' => $profile->weightUnit->value,
            'height_unit' => $profile->heightUnit->value,
            'appearance' => 'system',
            'consumed_totals' => 'planned',
            'eating_window' => [
                'start' => (string) new ClockTime($draft->eatingStartMinutes), 'end' => (string) new ClockTime($draft->eatingEndMinutes),
            ],
            'meal_count' => $draft->mealCount,
            'busy_periods' => array_map(fn (BusyPeriodChoice $period) => [
                'label' => $period->label,
                'start' => (string) new ClockTime($period->startMinutes),
                'end' => (string) new ClockTime($period->endMinutes),
                'weekdays' => $period->weekdays,
            ], $draft->busyPeriods),
            'training_days' => array_map(fn (TrainingDayChoice $day) => [
                'weekday' => $day->weekday, 'start' => (string) new ClockTime($day->startMinutes), 'minutes' => $day->lengthMinutes,
            ], $draft->trainingDays),
            'weigh_in_days' => array_map(fn (WeighInChoice $weighIn) => [
                'weekday' => $weighIn->weekday, 'time' => (string) new ClockTime($weighIn->minutes),
            ], $draft->weighIns),
            'step_plan' => array_map(fn (StepRangeChoice $range) => [
                'weekday' => $range->weekday, 'min' => $range->min, 'max' => $range->max,
            ], $start->stepRanges),
            'diet_filters' => [],
            'screening' => array_map(fn (ScreeningItem $item) => $item->value, $start->screening),
            'training_only' => $start->trainingOnly,
        ];
    }

    /** @return array{start_date: string, review_weekday: int} */
    public static function plan(OnboardingStart $start): array
    {
        return ['start_date' => self::startDate($start), 'review_weekday' => $start->reviewWeekday];
    }

    /** @return array<string, mixed> the first phase */
    public static function phase(OnboardingStart $start): array
    {
        $phase = $start->phase;

        return [
            'id' => $phase->id,
            'type' => $phase->type->value,
            'start_date' => self::startDate($start),
            'end_date' => $phase->end?->format('Y-m-d'),
            'target_rate_percent_per_week' => $phase->targetRatePercentPerWeek,
        ];
    }

    /**
     * The template's sessions with their exercises, or empty sessions for a draft that the Meso Builder or "build it
     * later" finishes (ONB-012, MESO-007).
     *
     * @return array<string, mixed> the first mesocycle, its days as `Mesocycle::$days` holds them
     */
    public static function mesocycle(OnboardingStart $start): array
    {
        $inputs = $start->inputs;
        $template = $inputs->template;
        $days = array_map(fn (int $index, Session $session) => [
            'id' => $session->id,
            'label' => $session->name,
            'weekday' => $inputs->sessionWeekdays[$index] ?? null,
            'slots' => $template === null ? [] : array_map(fn (int $position, ExerciseSlot $slot) => [
                'id' => $slot->id,
                'exercise_id' => $template->days[$index]->slots[$position]->exerciseId ?? null,
                'muscle_group' => $slot->muscle->value,
                'sets' => $slot->sets,
                'carried' => null,
            ], array_keys($session->slots), $session->slots),
        ], array_keys($inputs->mesocycle->sessions), $inputs->mesocycle->sessions);

        return [
            'id' => $inputs->mesocycle->id,
            'phase_id' => $start->phase->id,
            'name' => $start->mesocycleName,
            'weeks' => $inputs->mesocycle->weeks,
            'start_date' => self::startDate($start),
            'is_draft' => $template === null,
            'is_resensitization' => false,
            'route' => $start->route->value,
            'days' => $days,
            'muscle_priorities' => $start->musclePriorities === null
                ? null
                : array_map(fn (Priority $priority) => $priority->value, $start->musclePriorities),
        ];
    }

    /** @return array<string, mixed> plan week 0 */
    public static function planWeek(OnboardingStart $start, string $id): array
    {
        $week = $start->week->week;

        return [
            'id' => $id,
            'start_date' => $week->start->format('Y-m-d'),
            'is_deload' => $week->isDeload,
            'is_diet_break' => $week->isDietBreak,
            'is_away' => $week->isAway,
        ];
    }

    /**
     * Week one's days with their totals and meals, each day with a new id.
     *
     * @param  Closure(): string  $newId
     * @return list<array<string, mixed>>
     */
    public static function dayTargets(OnboardingStart $start, Closure $newId): array
    {
        return array_map(fn (WeekDay $day) => [
            'id' => $newId(),
            'date' => $day->day->date->format('Y-m-d'),
            'day_type' => $day->day->type->value,
            'kcal' => $day->target?->kcal,
            'protein_g' => $day->target?->proteinG,
            'fat_g' => $day->target?->fatG,
            'carbs_g' => $day->target?->carbsG,
            'meals' => array_map(fn (WeekMeal $meal) => [
                'id' => $meal->id(),
                'name' => $meal->meal->name,
                'time' => (string) $meal->time,
                'kcal' => $meal->meal->target->kcal,
                'protein_g' => $meal->meal->target->proteinG,
                'fat_g' => $meal->meal->target->fatG,
                'carbs_g' => $meal->meal->target->carbsG,
            ], $day->meals),
        ], $start->targetDays);
    }

    private static function startDate(OnboardingStart $start): string
    {
        return $start->inputs->plan->start->format('Y-m-d');
    }
}
