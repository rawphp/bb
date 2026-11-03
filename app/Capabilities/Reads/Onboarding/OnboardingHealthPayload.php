<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingAboutYouSection;
use App\Capabilities\Onboarding\Data\OnboardingAdvice;
use App\Capabilities\Onboarding\Data\OnboardingBodyFatSection;
use App\Capabilities\Onboarding\Data\OnboardingGoalSection;
use App\Capabilities\Onboarding\Data\OnboardingHeight;
use App\Capabilities\Onboarding\Data\OnboardingQuestion;
use App\Capabilities\Onboarding\Data\OnboardingScreeningRow;
use App\Capabilities\Onboarding\Data\OnboardingScreeningSection;
use App\Capabilities\Onboarding\Data\OnboardingWeight;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\GoalOption;
use App\Domain\Onboarding\OnboardingDates;
use App\Domain\Onboarding\OnboardingHealth;
use App\Domain\Onboarding\OnboardingText;
use App\Domain\Onboarding\ScreeningRow;
use DateTimeImmutable;

/** Steps 1 to 5 of onboarding_show: goal, about you, screening, the weight-loss drug question and body fat. */
final class OnboardingHealthPayload
{
    public static function goal(Draft $draft, OnboardingHealth $health): OnboardingGoalSection
    {
        $advice = $health->maintenanceFirstAdvice();

        return new OnboardingGoalSection(
            help: OnboardingHealth::GOAL_HELP,
            options: array_map(fn (GoalOption $option) => OnboardingParts::choice(
                $option->goal->value, $option->title, $draft->goal === $option->goal, $option->detail ?? '', $option->isEnabled,
            ), $health->goalOptions()),
            recent_diet: new OnboardingQuestion(
                question: OnboardingHealth::RECENT_DIET_QUESTION, detail: OnboardingHealth::RECENT_DIET_DETAIL,
                answer: $draft->finishedDietRecently,
            ),
            advice: $advice === null ? null : new OnboardingAdvice(
                text: $advice->text, action_title: $advice->actionTitle, action_goal: PhaseType::Maintenance->value,
            ),
        );
    }

    public static function aboutYou(Draft $draft, OnboardingHealth $health, DateTimeImmutable $today): OnboardingAboutYouSection
    {
        return new OnboardingAboutYouSection(
            name: $draft->name,
            sex_options: array_map(fn (Sex $sex) => OnboardingParts::choice($sex->value, ucfirst($sex->value), $draft->sex === $sex), Sex::cases()),
            birth_date: OnboardingParts::date(
                $draft->birthDate, $draft->birthDate ?? $health->defaultBirthDate(), null, OnboardingDates::addingDays($today, -1),
            ),
            height: self::height($draft),
            weight: self::weight($draft),
        );
    }

    public static function screening(OnboardingHealth $health): OnboardingScreeningSection
    {
        return new OnboardingScreeningSection(
            prompt: OnboardingHealth::SCREENING_PROMPT,
            clearance_note: OnboardingParts::status(OnboardingHealth::MEDICAL_CLEARANCE_NOTE, 'warning'),
            medical_diets_note: OnboardingParts::status(OnboardingHealth::MEDICAL_DIETS_NOTE, 'info'),
            rows: array_map(fn (ScreeningRow $row) => new OnboardingScreeningRow(
                item: $row->item->value, label: $row->label, detail: $row->detail ?? '', is_on: $row->isOn, is_locked: $row->isLocked,
            ), $health->screeningRows()),
        );
    }

    public static function glp1(Draft $draft): OnboardingQuestion
    {
        return new OnboardingQuestion(
            question: OnboardingHealth::GLP1_QUESTION, detail: OnboardingHealth::GLP1_DETAIL, answer: $draft->usesGLP1,
        );
    }

    public static function bodyFat(Draft $draft, OnboardingHealth $health): OnboardingBodyFatSection
    {
        return new OnboardingBodyFatSection(
            help: OnboardingHealth::BODY_FAT_HELP,
            sex: $draft->sex?->value,
            options: array_map(fn ($row) => OnboardingParts::choice($row->bucket->value, $row->label, $draft->bodyFatBucket === $row->bucket), $health->bodyFatRows()),
        );
    }

    private static function height(Draft $draft): OnboardingHeight
    {
        $cm = $draft->heightCm;
        $feet = null;
        $inches = null;
        if ($cm !== null) {
            [$feet, $inches] = UnitConversion::cmToFeetInches($cm);
            $inches = OnboardingParts::oneDecimal($inches);
            if ($inches >= 12.0) {
                $feet++;
                $inches = 0.0;
            }
        }

        return new OnboardingHeight(
            unit: $draft->heightUnit->value,
            unit_options: [
                OnboardingParts::choice(HeightUnit::Cm->value, 'cm', $draft->heightUnit === HeightUnit::Cm),
                OnboardingParts::choice(HeightUnit::FeetInches->value, 'ft and in', $draft->heightUnit === HeightUnit::FeetInches),
            ],
            cm: $cm === null ? null : OnboardingParts::oneDecimal($cm),
            feet: $feet,
            inches: $inches,
            text: $cm === null ? '' : OnboardingText::heightText($cm, $draft->heightUnit),
        );
    }

    private static function weight(Draft $draft): OnboardingWeight
    {
        $kg = $draft->weightKg;

        return new OnboardingWeight(
            unit: $draft->weightUnit->value,
            unit_options: array_map(fn (WeightUnit $unit) => OnboardingParts::choice($unit->value, $unit->value, $draft->weightUnit === $unit), WeightUnit::cases()),
            value: $kg === null ? null : OnboardingParts::oneDecimal(OnboardingText::weightIn($kg, $draft->weightUnit)),
            text: $kg === null ? '' : OnboardingText::weightText($kg, $draft->weightUnit),
        );
    }
}
