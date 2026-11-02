<?php

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\ScreeningItem;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use App\Models\Exercise;
use App\Models\OnboardingDraft;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// ONB-002: the setup draft, one row per user, ported from rp-clone's OnboardingDraftTests. The device store becomes
// the onboarding_drafts table: load is OnboardingDraft::for (a user with no row reads the defaults and nothing is
// written), save is a save, and clear is a delete.
uses(RefreshDatabase::class);

/** Every answer of steps 1 to 13 set, none of them a default. */
function fullDraft(int $templateId): Draft
{
    return new Draft(
        step: 13,
        goal: PhaseType::FatLoss,
        finishedDietRecently: false,
        name: 'Sam',
        sex: Sex::Female,
        birthDate: F::date(1990, 4, 9),
        heightCm: 170.5,
        weightKg: 72.25,
        weightUnit: WeightUnit::Lb,
        heightUnit: HeightUnit::FeetInches,
        screening: [ScreeningItem::TryingToConceive, ScreeningItem::Diabetes],
        usesGLP1: true,
        bodyFatBucket: BodyFatBucket::High,
        ratePercentPerWeek: -0.75,
        customRate: true,
        startDate: F::date(2026, 10, 11),
        weeks: 12,
        experience: ExperienceLevel::Advanced,
        trainingDays: [new TrainingDayChoice(1, 390, 75), new TrainingDayChoice(4, 1080, 60)],
        trainingPlan: TrainingPlanKind::Template,
        templateId: $templateId,
        stepRanges: [new StepRangeChoice(1, 0, 8000), new StepRangeChoice(6, 8000, 13000)],
        eatingStartMinutes: 390,
        eatingEndMinutes: 1260,
        mealCount: 4,
        busyPeriods: [new BusyPeriodChoice('01JBX0Q5ZK3J7Y8T6V2N4M1C9D', 'Commute', 480, 540, [1, 2, 3, 4, 5])],
        weighIns: [new WeighInChoice(2, 420), new WeighInChoice(5, 450)],
        reviewWeekday: 6,
        choosesOwnCalories: true,
        ownDailyKcal: 2150,
    );
}

it('ONB-002 a saved draft reads back the same step and answers', function () {
    $user = User::factory()->create();
    $answers = fullDraft(Template::factory()->create(['days_per_week' => 2, 'split' => Split::UpperLower])->id);
    OnboardingDraft::for($user)->fillFrom($answers)->save();

    expect(OnboardingDraft::for($user->fresh())->toDraft())->toEqual($answers);
});

it('ONB-002 SAFE-001 reads the ticked screening items in the spec\'s order, whatever order they were stored in', function () {
    $user = User::factory()->create();
    OnboardingDraft::for($user)->fill(['screening' => ['diabetes', 'tryingToConceive']])->save();

    expect(OnboardingDraft::for($user)->toDraft()->screening)->toBe([ScreeningItem::TryingToConceive, ScreeningItem::Diabetes]);
});

it('ONB-002 keeps one draft per user and saving again updates it', function () {
    $user = User::factory()->create();
    $draft = OnboardingDraft::for($user);
    $draft->fill(['step' => 2, 'name' => 'Sam'])->save();

    $again = OnboardingDraft::for($user);
    $again->fill(['step' => 3])->save();

    expect(OnboardingDraft::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(OnboardingDraft::for($user)->toDraft()->step)->toBe(3)
        ->and(OnboardingDraft::for($user)->toDraft()->name)->toBe('Sam');
});

it('ONB-002 a user with no draft reads the defaults and reading writes nothing', function () {
    $user = User::factory()->create();

    $draft = OnboardingDraft::for($user);

    expect($draft->exists)->toBeFalse()
        ->and($draft->user_id)->toBe($user->id)
        ->and($draft->toDraft())->toEqual(new Draft)
        ->and(OnboardingDraft::query()->count())->toBe(0);
});

it('ONB-002 ONB-004 a new draft starts at step 1 with no answers, in kg and cm, with the step 11 defaults', function () {
    $draft = (new OnboardingDraft)->toDraft();

    expect($draft->step)->toBe(1)
        ->and($draft->goal)->toBeNull()
        ->and($draft->finishedDietRecently)->toBeNull()
        ->and($draft->name)->toBe('')
        ->and($draft->weightUnit)->toBe(WeightUnit::Kg)
        ->and($draft->heightUnit)->toBe(HeightUnit::Cm)
        ->and($draft->screening)->toBe([])
        ->and($draft->usesGLP1)->toBeNull()
        ->and($draft->bodyFatBucket)->toBeNull()
        ->and($draft->customRate)->toBeFalse()
        ->and($draft->weeks)->toBeNull()
        ->and($draft->experience)->toBeNull()
        ->and($draft->trainingDays)->toBe([])
        ->and($draft->trainingPlan)->toBeNull()
        ->and($draft->templateId)->toBeNull()
        ->and($draft->stepRanges)->toBe([])
        ->and($draft->eatingStartMinutes)->toBe(7 * 60)
        ->and($draft->eatingEndMinutes)->toBe(20 * 60)
        ->and($draft->mealCount)->toBe(5)
        ->and($draft->busyPeriods)->toBe([])
        ->and($draft->weighIns)->toBe([])
        ->and($draft->reviewWeekday)->toBeNull()
        ->and($draft->choosesOwnCalories)->toBeFalse()
        ->and($draft->ownDailyKcal)->toBeNull();
});

it('ONB-002 deleting the account deletes its draft (ACC-005)', function () {
    $user = User::factory()->create();
    OnboardingDraft::for($user)->fill(['step' => 4])->save();

    $user->delete();

    expect(OnboardingDraft::query()->count())->toBe(0);
});

it('ONB-012 turns a library template into the onboarding value, days in order with their slots', function () {
    $template = Template::factory()->create([
        'name' => 'Full body two', 'split' => Split::FullBody, 'days_per_week' => 2, 'sex' => SexFit::Female,
        'phase_fit' => [PhaseFit::Cut, PhaseFit::Maintain], 'priorities' => ['chest' => 'grow'],
        'day_labels' => ['Day A', 'Day B'],
    ]);
    $bench = Exercise::factory()->create(['name' => 'Bench press', 'muscle_group' => MuscleGroup::Chest]);
    $row = Exercise::factory()->create(['name' => 'Row', 'muscle_group' => MuscleGroup::Back]);
    TemplateSlot::factory()->for($template)->create(['day' => 2, 'position' => 1, 'muscle_group' => MuscleGroup::Back, 'exercise_id' => $row->id, 'sets' => 4]);
    TemplateSlot::factory()->for($template)->create(['day' => 1, 'position' => 1, 'muscle_group' => MuscleGroup::Chest, 'exercise_id' => $bench->id, 'sets' => 3]);

    $value = OnboardingDraft::onboardingTemplate($template->fresh()->load('slots.exercise'));

    expect($value->id)->toBe($template->id)
        ->and($value->name)->toBe('Full body two')
        ->and($value->split)->toBe(Split::FullBody)
        ->and($value->daysPerWeek)->toBe(2)
        ->and($value->sex)->toBe(SexFit::Female)
        ->and($value->phaseFit)->toBe([PhaseFit::Cut, PhaseFit::Maintain])
        ->and($value->priorities)->toBe(['chest' => 'grow'])
        ->and(array_map(fn ($day) => $day->label, $value->days))->toBe(['Day A', 'Day B'])
        ->and($value->days[0]->slots[0]->exerciseName)->toBe('Bench press')
        ->and($value->days[0]->slots[0]->muscle)->toBe(MuscleGroup::Chest)
        ->and($value->days[0]->slots[0]->sets)->toBe(3)
        ->and($value->days[1]->slots[0]->exerciseName)->toBe('Row')
        ->and($value->days[1]->slots[0]->sets)->toBe(4);
});
