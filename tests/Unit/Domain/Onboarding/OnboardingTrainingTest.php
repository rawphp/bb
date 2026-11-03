<?php

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\ExperienceOption;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\OnboardingTraining;
use App\Domain\Onboarding\TemplateOption;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// Steps 7 to 9 (ONB-010 to ONB-012), the read cases of rp-clone's OnboardingTrainingTests. The templates are passed
// in as the server loaded them; picking one, toggling a day or setting a time is #67's write.

/** @return list<OnboardingTemplate> */
function onboardingTrainingCatalogue(): array
{
    return [
        F::template(1, 'Balanced three', Split::UpperLower, phaseFit: [PhaseFit::Maintain, PhaseFit::Cut]),
        F::template(2, 'Full body three', Split::FullBody, phaseFit: [PhaseFit::Cut, PhaseFit::Maintain]),
        F::template(3, 'Delts three', Split::Specialisation, phaseFit: [PhaseFit::Gain, PhaseFit::Cut, PhaseFit::Maintain]),
    ];
}

/** @param  ?list<OnboardingTemplate>  $templates */
function onboardingTraining(Draft $draft, ?array $templates = null): OnboardingTraining
{
    return new OnboardingTraining($draft, $templates ?? onboardingTrainingCatalogue());
}

/** @return list<int> */
function onboardingTemplateIds(OnboardingTraining $training): array
{
    return array_map(fn (TemplateOption $option) => $option->template->id, $training->templateOptions());
}

// ONB-010 experience

it('ONB-010 offers three experience options with the spec year ranges and their mesocycle length', function () {
    $options = OnboardingTraining::experienceOptions();

    expect(array_map(fn (ExperienceOption $o) => $o->level, $options))->toBe(ExperienceLevel::cases())
        ->and(array_map(fn (ExperienceOption $o) => $o->title, $options))->toBe(['Beginner', 'Intermediate', 'Advanced'])
        ->and(array_map(fn (ExperienceOption $o) => $o->detail, $options))->toBe(['Under 1 year of consistent lifting', '1 to 4 years', 'Over 4 years'])
        ->and(array_map(fn (ExperienceOption $o) => $o->mesocycleWeeks, $options))->toBe([6, 5, 5]);
});

it('ONB-010 the choice sets the first mesocycle length from TBL-020', function () {
    expect(onboardingTraining(F::atStep7())->mesocycleWeeks())->toBeNull()
        ->and(onboardingTraining(F::atStep7()->with(experience: ExperienceLevel::Beginner))->mesocycleWeeks())->toBe(6)
        ->and(onboardingTraining(F::atStep7()->with(experience: ExperienceLevel::Intermediate))->mesocycleWeeks())->toBe(5)
        ->and(onboardingTraining(F::atStep7()->with(experience: ExperienceLevel::Advanced))->mesocycleWeeks())->toBe(5);
});

// ONB-011 training days

it('ONB-011 help text says 2 to 4 repeatable days beat 5 or 6 missed ones', function () {
    expect(OnboardingTraining::TRAINING_DAYS_HELP)->toBe('2 to 4 days you can repeat every week beat 5 or 6 you will miss.');
});

it('ONB-011 a picked day starts at 6 PM for 60 minutes, and a length stays between 15 and 300 minutes', function () {
    expect(OnboardingTraining::DEFAULT_START_MINUTES)->toBe(18 * 60)
        ->and(OnboardingTraining::DEFAULT_LENGTH_MINUTES)->toBe(60)
        ->and(OnboardingTraining::MINIMUM_LENGTH_MINUTES)->toBe(15)
        ->and(OnboardingTraining::MAXIMUM_LENGTH_MINUTES)->toBe(300);
});

// ONB-012 training plan

it('ONB-012 templates for another sex or another phase are left out and do not count as picked', function () {
    $templates = [
        ...onboardingTrainingCatalogue(),
        F::template(8, 'Men only', Split::FullBody, sex: SexFit::Male),
        F::template(9, 'Bulk only', Split::FullBody, phaseFit: [PhaseFit::Gain]),
    ];
    $fatLoss = F::atStep9(PhaseType::FatLoss);

    expect(onboardingTemplateIds(onboardingTraining($fatLoss, $templates)))->toBe([2, 1, 3])
        ->and(onboardingTraining($fatLoss, $templates)->fits($templates[3]))->toBeFalse()
        ->and(onboardingTraining($fatLoss, $templates)->fits($templates[4]))->toBeFalse()
        ->and(onboardingTraining($fatLoss->with(trainingPlan: TrainingPlanKind::Template, templateId: 8), $templates)->chosenTemplate())->toBeNull()
        ->and(onboardingTraining($fatLoss->with(trainingPlan: TrainingPlanKind::Template, templateId: 9), $templates)->chosenTemplate())->toBeNull();
});

it('ONB-012 a female-only template fits a woman', function () {
    $template = F::template(7, 'Women three', sex: SexFit::Female);

    expect(onboardingTraining(F::atStep9(), [$template])->fits($template))->toBeTrue();
});

it('ONB-012 Fat loss preselects a full-body template', function () {
    $training = onboardingTraining(F::atStep9(PhaseType::FatLoss));

    expect($training->recommendedTemplate()?->id)->toBe(2)
        ->and($training->trainingPlanKind())->toBe(TrainingPlanKind::Template)
        ->and($training->selectedTemplate()?->id)->toBe(2)
        ->and($training->chosenTemplate())->toBeNull();
});

it('ONB-012 Muscle gain preselects a specialisation template', function () {
    expect(onboardingTraining(F::atStep9(PhaseType::MuscleGain))->selectedTemplate()?->id)->toBe(3);
});

it('ONB-012 Maintenance preselects a balanced template', function () {
    expect(onboardingTraining(F::atStep9(PhaseType::Maintenance))->selectedTemplate()?->id)->toBe(1);
});

it('ONB-012 the recommended templates come first and are marked', function () {
    $training = onboardingTraining(F::atStep9(PhaseType::FatLoss));

    expect(onboardingTemplateIds($training))->toBe([2, 1, 3])
        ->and(array_map(fn (TemplateOption $o) => $o->isRecommended, $training->templateOptions()))->toBe([true, false, false])
        ->and(OnboardingTraining::isRecommended(onboardingTrainingCatalogue()[1], PhaseType::FatLoss))->toBeTrue()
        ->and(OnboardingTraining::isRecommended(onboardingTrainingCatalogue()[2], PhaseType::MuscleGain))->toBeTrue()
        ->and(OnboardingTraining::isRecommended(onboardingTrainingCatalogue()[0], PhaseType::Maintenance))->toBeTrue()
        ->and(OnboardingTraining::isRecommended(onboardingTrainingCatalogue()[2], PhaseType::Maintenance))->toBeFalse();
});

it('ONB-012 another template, the Meso Builder or build later is the user\'s pick', function () {
    $draft = F::atStep9(PhaseType::FatLoss);
    $other = onboardingTraining($draft->with(trainingPlan: TrainingPlanKind::Template, templateId: 3));
    $builder = onboardingTraining($draft->with(trainingPlan: TrainingPlanKind::MesoBuilder));
    $later = onboardingTraining($draft->with(trainingPlan: TrainingPlanKind::Later));

    expect($other->selectedTemplate()?->id)->toBe(3)
        ->and($other->chosenTemplate()?->id)->toBe(3)
        ->and($builder->trainingPlanKind())->toBe(TrainingPlanKind::MesoBuilder)
        ->and($builder->selectedTemplate())->toBeNull()
        ->and($later->trainingPlanKind())->toBe(TrainingPlanKind::Later)
        ->and($later->selectedTemplate())->toBeNull();
});

it('ONB-012 with nothing to recommend and nothing picked there is no plan yet', function () {
    expect(onboardingTraining(F::atStep9(), [])->trainingPlanKind())->toBeNull()
        ->and(onboardingTraining(F::atStep9(), [])->templateOptions())->toBe([]);
});

it('ONB-012 templates exist only for 2 to 6 training days', function () {
    $oneDay = onboardingTraining(F::atStep9(days: [1]), [F::template(4, 'One', days: 1)]);

    expect($oneDay->templateOptions())->toBe([])
        ->and($oneDay->noTemplatesMessage())->toBe('Templates are made for 2 to 6 training days. Use the Meso Builder, or build your plan later.')
        ->and(onboardingTraining(F::atStep9())->noTemplatesMessage())->toBeNull();
});

it('ONB-012 changing the training days drops a template that no longer fits', function () {
    $draft = F::atStep9(PhaseType::FatLoss)->with(
        trainingPlan: TrainingPlanKind::Template, templateId: 2, trainingDays: F::trainingDays([1, 2, 3, 5]),
    );

    expect(onboardingTraining($draft)->chosenTemplate())->toBeNull()
        ->and(onboardingTraining($draft)->selectedTemplate())->toBeNull()
        ->and(onboardingTraining($draft)->trainingPlanKind())->toBeNull();
});

it('ONB-012 a template row says its days and its split', function () {
    $templates = [
        F::template(1, 'A', Split::FullBody),
        F::template(2, 'B', Split::UpperLower),
        F::template(3, 'C', Split::PushPullLegs),
        F::template(4, 'D', Split::Specialisation),
    ];
    $summaries = array_map(fn (TemplateOption $o) => [$o->template->name, $o->summary()], onboardingTraining(F::atStep9(), $templates)->templateOptions());

    expect(array_column($summaries, 1, 0))->toBe([
        'A' => '3 days a week · Full body',
        'B' => '3 days a week · Upper / lower',
        'C' => '3 days a week · Push / pull / legs',
        'D' => '3 days a week · Specialisation',
    ]);
});

it('ONB-012 without a goal or a sex no template fits', function () {
    expect(onboardingTraining(F::atStep9()->with(goal: null))->templateOptions())->toBe([])
        ->and(onboardingTraining(F::atStep9()->with(sex: null))->templateOptions())->toBe([]);
});
