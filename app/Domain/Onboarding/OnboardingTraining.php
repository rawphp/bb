<?php

namespace App\Domain\Onboarding;

use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;

/**
 * Steps 7 to 9 (ONB-010 to ONB-012), the read members of rp-clone's OnboardingModel+Training. The caller passes the
 * library templates in; the rules keep the ones that fit the day count, sex and goal.
 *
 * rp-clone preselects the recommended template by writing it into the draft when step 9 loads. Here a read never
 * writes, so while nothing is picked (or the pick no longer fits) the recommended template stands as the selection.
 */
final readonly class OnboardingTraining
{
    public const TRAINING_DAYS_HELP = '2 to 4 days you can repeat every week beat 5 or 6 you will miss.';

    public const DEFAULT_START_MINUTES = 18 * 60;

    public const DEFAULT_LENGTH_MINUTES = 60;

    public const MINIMUM_LENGTH_MINUTES = 15;

    public const MAXIMUM_LENGTH_MINUTES = 300;

    /** Templates exist for 2 to 6 training days. */
    public const TEMPLATE_DAY_COUNTS = [2, 3, 4, 5, 6];

    public const NO_TEMPLATES_MESSAGE = 'Templates are made for 2 to 6 training days. Use the Meso Builder, or build your plan later.';

    /** @param  list<OnboardingTemplate>  $templates  the library, in the server's order */
    public function __construct(private Draft $draft, private array $templates) {}

    // Step 7 (ONB-010)

    /** @return list<ExperienceOption> */
    public static function experienceOptions(): array
    {
        return array_map(fn (array $option) => new ExperienceOption(
            $option[2], $option[0], $option[1], TBL020MesocycleLength::row($option[2])->defaultWeeks,
        ), [
            ['Beginner', 'Under 1 year of consistent lifting', ExperienceLevel::Beginner],
            ['Intermediate', '1 to 4 years', ExperienceLevel::Intermediate],
            ['Advanced', 'Over 4 years', ExperienceLevel::Advanced],
        ]);
    }

    /** TBL-020: the first mesocycle's default length, deload included. */
    public function mesocycleWeeks(): ?int
    {
        return $this->draft->experience === null ? null : TBL020MesocycleLength::row($this->draft->experience)->defaultWeeks;
    }

    // Step 9 (ONB-012)

    /**
     * The templates that fit, recommended first, each group in the server's order.
     *
     * @return list<TemplateOption>
     */
    public function templateOptions(): array
    {
        $goal = $this->draft->goal;
        if ($goal === null || $this->noTemplatesMessage() !== null) {
            return [];
        }
        $options = array_map(
            fn (OnboardingTemplate $template) => new TemplateOption($template, self::isRecommended($template, $goal)),
            array_values(array_filter($this->templates, fn (OnboardingTemplate $template) => $this->fits($template))),
        );

        return [
            ...array_filter($options, fn (TemplateOption $option) => $option->isRecommended),
            ...array_filter($options, fn (TemplateOption $option) => ! $option->isRecommended),
        ];
    }

    /** Why there are no templates to pick: the day count has none. */
    public function noTemplatesMessage(): ?string
    {
        return in_array(count($this->draft->trainingDays), self::TEMPLATE_DAY_COUNTS, true) ? null : self::NO_TEMPLATES_MESSAGE;
    }

    /** Whether the template suits the day count, sex and goal (the library filters by the same three). */
    public function fits(OnboardingTemplate $template): bool
    {
        $goal = $this->draft->goal;
        $sex = $this->draft->sex;

        return $goal !== null && $sex !== null
            && $template->daysPerWeek === count($this->draft->trainingDays)
            && ($template->sex === SexFit::Any || $template->sex->value === $sex->value)
            && in_array(PhaseFit::for($goal), $template->phaseFit, true);
    }

    /** ONB-012: full-body for Fat loss, a specialisation for Muscle gain, a balanced (whole-body) one for Maintenance. */
    public static function isRecommended(OnboardingTemplate $template, PhaseType $goal): bool
    {
        return match ($goal) {
            PhaseType::FatLoss, PhaseType::MiniCut => $template->split === Split::FullBody,
            PhaseType::MuscleGain => $template->split === Split::Specialisation,
            PhaseType::Maintenance => $template->split !== Split::Specialisation,
        };
    }

    /** The first recommended template that fits. */
    public function recommendedTemplate(): ?OnboardingTemplate
    {
        foreach ($this->templateOptions() as $option) {
            if ($option->isRecommended) {
                return $option->template;
            }
        }

        return null;
    }

    /** The template the user picked, while it still fits the answers above it. */
    public function chosenTemplate(): ?OnboardingTemplate
    {
        if ($this->draft->trainingPlan !== TrainingPlanKind::Template) {
            return null;
        }
        foreach ($this->templates as $template) {
            if ($template->id === $this->draft->templateId) {
                return $this->fits($template) ? $template : null;
            }
        }

        return null;
    }

    /** The user's pick, or the recommended template while nothing (that still fits) is picked. */
    public function trainingPlanKind(): ?TrainingPlanKind
    {
        $picked = $this->draft->trainingPlan;
        if ($picked !== null && ($picked !== TrainingPlanKind::Template || $this->chosenTemplate() !== null)) {
            return $picked;
        }

        return $this->recommendedTemplate() === null ? null : TrainingPlanKind::Template;
    }

    /** The template the first mesocycle takes its sessions from; null for the Meso Builder or "build later". */
    public function selectedTemplate(): ?OnboardingTemplate
    {
        if ($this->trainingPlanKind() !== TrainingPlanKind::Template) {
            return null;
        }

        return $this->chosenTemplate() ?? $this->recommendedTemplate();
    }

    /** "Upper / lower". */
    public static function splitTitle(Split $split): string
    {
        return match ($split) {
            Split::FullBody => 'Full body',
            Split::UpperLower => 'Upper / lower',
            Split::PushPullLegs => 'Push / pull / legs',
            Split::Specialisation => 'Specialisation',
        };
    }
}
