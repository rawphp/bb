<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingExperienceSection;
use App\Capabilities\Onboarding\Data\OnboardingTrainingDay;
use App\Capabilities\Onboarding\Data\OnboardingTrainingDaysSection;
use App\Capabilities\Onboarding\Data\OnboardingTrainingPlanSection;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\ExperienceOption;
use App\Domain\Onboarding\OnboardingText;
use App\Domain\Onboarding\OnboardingTraining;
use App\Domain\Onboarding\TemplateOption;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\TrainingPlanKind;

/** Steps 7 to 9 of onboarding_show: experience, training days and the training plan. */
final class OnboardingTrainingPayload
{
    private const LENGTH_STEP = 15;

    public const MESO_BUILDER_DETAIL = 'Set a priority for each muscle. Its screen comes later, so your plan is built later for now.';

    public const LATER_DETAIL = 'The schedule shows workout blocks with no exercises until you do.';

    public static function experience(Draft $draft): OnboardingExperienceSection
    {
        return new OnboardingExperienceSection(options: array_map(
            fn (ExperienceOption $option) => OnboardingParts::choice(
                $option->level->value, $option->title, $draft->experience === $option->level,
                "{$option->detail}. Mesocycles start at {$option->mesocycleWeeks} weeks.",
            ),
            OnboardingTraining::experienceOptions(),
        ));
    }

    public static function trainingDays(Draft $draft): OnboardingTrainingDaysSection
    {
        return new OnboardingTrainingDaysSection(
            help: OnboardingTraining::TRAINING_DAYS_HELP,
            days: array_map(function (int $weekday) use ($draft) {
                $chosen = array_find($draft->trainingDays, fn (TrainingDayChoice $day) => $day->weekday === $weekday);
                $start = $chosen->startMinutes ?? OnboardingTraining::DEFAULT_START_MINUTES;
                $length = $chosen->lengthMinutes ?? OnboardingTraining::DEFAULT_LENGTH_MINUTES;

                return new OnboardingTrainingDay(
                    weekday: $weekday, name: OnboardingText::weekdayName($weekday), full_name: OnboardingText::weekdayFullName($weekday),
                    is_on: $chosen !== null, start: OnboardingParts::time($start), length_minutes: $length, length_text: "Length: {$length} min",
                );
            }, range(1, 7)),
            length_min: OnboardingTraining::MINIMUM_LENGTH_MINUTES,
            length_max: OnboardingTraining::MAXIMUM_LENGTH_MINUTES,
            length_step: self::LENGTH_STEP,
        );
    }

    public static function trainingPlan(Draft $draft, OnboardingTraining $training): OnboardingTrainingPlanSection
    {
        $kind = $training->trainingPlanKind();
        $selectedId = $training->selectedTemplate()?->id;
        $options = array_map(fn (TemplateOption $option) => OnboardingParts::choice(
            "template:{$option->template->id}", $option->template->name, $option->template->id === $selectedId, $option->summary(),
            recommended: $option->isRecommended,
        ), $training->templateOptions());

        return new OnboardingTrainingPlanSection(
            note: self::note($draft, $training, $options !== []),
            options: [
                ...$options,
                OnboardingParts::choice(TrainingPlanKind::MesoBuilder->value, 'Meso Builder', $kind === TrainingPlanKind::MesoBuilder, self::MESO_BUILDER_DETAIL),
                OnboardingParts::choice(TrainingPlanKind::Later->value, "I'll build it later", $kind === TrainingPlanKind::Later, self::LATER_DETAIL),
            ],
        );
    }

    /** Why the templates are what they are; "" before the goal and sex are answered, as there is nothing to filter by. */
    private static function note(Draft $draft, OnboardingTraining $training, bool $hasTemplates): string
    {
        if ($draft->goal === null || $draft->sex === null) {
            return '';
        }

        return $training->noTemplatesMessage()
            ?? ($hasTemplates ? 'Templates that fit your '.count($draft->trainingDays).' training days, sex and goal.' : '');
    }
}
