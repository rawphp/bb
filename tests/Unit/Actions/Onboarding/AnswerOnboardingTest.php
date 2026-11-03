<?php

use App\Actions\Onboarding\AnswerOnboarding;
use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingLifestyle;
use App\Domain\Onboarding\OnboardingRateLength;
use App\Domain\Onboarding\OnboardingSteps;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\ScreeningItem;
use App\Models\OnboardingDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// onboarding_answer's Action (ONB-002 to ONB-018, SAFE-001, SAFE-013): each answer through the rule of rp-clone's
// mutating member, then the move OnboardingSteps names. The rules run on a Draft with the template library and the
// new ids handed in, so only the ONB-002 cases touch the database. Today is Saturday 10 October 2026 (F::today()).
uses(RefreshDatabase::class);

/**
 * The library as OnboardingDraft::templates() loads it: the templates for the draft's day count.
 *
 * @return list<OnboardingTemplate>
 */
function answerTemplates(Draft $draft): array
{
    return array_values(array_filter(F::catalogue(), fn (OnboardingTemplate $template) => $template->daysPerWeek === count($draft->trainingDays)));
}

/** @param  array<string, mixed>  $input  as sent over the wire */
function answerDraft(Draft $draft, array $input): Draft
{
    $ids = 0;

    return AnswerOnboarding::answer(
        $draft, OnboardingAnswerInput::from($input), F::today(), answerTemplates(...), function () use (&$ids) {
            $ids++;

            return "new-{$ids}";
        },
    );
}

/** @param  array<string, mixed>  $input */
function answerRefusal(Draft $draft, array $input): FieldRefused
{
    try {
        answerDraft($draft, $input);
    } catch (FieldRefused $refused) {
        return $refused;
    }
    throw new RuntimeException('The answer was not refused.');
}

// ONB-002

describe('on the database', function () {
    beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
    afterEach(fn () => Carbon::setTestNow());

    it('ONB-002 saves an answer and the step it moves to, and a reload reads both back', function () {
        $user = User::factory()->create();
        $answer = app(AnswerOnboarding::class);

        $answer($user, OnboardingAnswerInput::from(['answers' => ['goal' => 'muscleGain', 'finished_diet_recently' => false], 'move' => 'next']));
        $answer($user, OnboardingAnswerInput::from(['answers' => ['name' => 'Sam']]));

        $draft = OnboardingDraft::for($user->fresh())->toDraft();
        expect(OnboardingDraft::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and($draft->step)->toBe(2)
            ->and($draft->goal)->toBe(PhaseType::MuscleGain)
            ->and($draft->finishedDietRecently)->toBeFalse()
            ->and($draft->name)->toBe('Sam')
            ->and($draft->ratePercentPerWeek)->toBe(0.375)
            ->and($draft->startDate?->format('Y-m-d'))->toBe('2026-10-11');
    });

    it('ONB-002 saves nothing when Next is refused', function () {
        $user = User::factory()->create();

        expect(fn () => app(AnswerOnboarding::class)($user, OnboardingAnswerInput::from(['answers' => ['goal' => 'fatLoss'], 'move' => 'next'])))
            ->toThrow(FieldRefused::class, 'Pick a goal and answer the question to continue.')
            ->and(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeFalse();
    });
});

it('ONB-002 stay saves the answers and keeps the step', function () {
    $draft = answerDraft(F::atStep2(), ['answers' => ['name' => 'Sam'], 'move' => 'stay']);

    expect($draft->step)->toBe(2)->and($draft->name)->toBe('Sam');
});

it('ONB-002 a key left out or sent as null keeps the saved answer', function () {
    $draft = answerDraft(F::atStep3(), ['answers' => ['name' => null, 'sex' => null, 'height' => null]]);

    expect($draft->name)->toBe('Sam')->and($draft->sex)->toBe(Sex::Female)->and($draft->heightCm)->toBe(170.0);
});

// Step 1 (ONB-003, ONB-021)

it('ONB-003 ONB-021 saves the goal and the recent-diet answer, and Next moves to step 2', function () {
    $draft = answerDraft(new Draft, ['answers' => ['goal' => 'fatLoss', 'finished_diet_recently' => true], 'move' => 'next']);

    expect($draft->step)->toBe(2)
        ->and($draft->goal)->toBe(PhaseType::FatLoss)
        ->and($draft->finishedDietRecently)->toBeTrue();
});

it('ONB-003 refuses Next on step 1 until the goal and the recent-diet answer are in, with the step\'s hint', function () {
    $refused = answerRefusal(new Draft, ['answers' => ['goal' => 'fatLoss'], 'move' => 'next']);

    expect($refused->field)->toBe('move')
        ->and($refused->getMessage())->toBe((new OnboardingSteps(new Draft, F::today(), []))->continueHintOn(1));
});

it('ONB-021 "Start with Maintenance" switches the goal and takes Maintenance\'s step 6 defaults', function () {
    $draft = answerDraft(F::atStep2(PhaseType::FatLoss, recentDiet: true), ['answers' => ['goal' => 'maintenance']]);

    expect($draft->goal)->toBe(PhaseType::Maintenance)
        ->and($draft->ratePercentPerWeek)->toBe(0.0)
        ->and($draft->weeks)->toBe(4);
});

it('SAFE-001 ONB-005 refuses a Fat loss goal while trying to conceive', function () {
    $blocked = F::atStep3(PhaseType::Maintenance)->with(step: 1, screening: [ScreeningItem::TryingToConceive]);
    $refused = answerRefusal($blocked, ['answers' => ['goal' => 'fatLoss']]);

    expect($refused->field)->toBe('answers.goal')
        ->and(answerDraft($blocked, ['answers' => ['goal' => 'muscleGain']])->goal)->toBe(PhaseType::MuscleGain);
});

it('SAFE-001 ONB-005 checks a goal against the screening sent with it', function () {
    $blocked = F::atStep3(PhaseType::Maintenance)->with(step: 1, screening: [ScreeningItem::TryingToConceive]);
    $draft = answerDraft($blocked, ['answers' => ['goal' => 'fatLoss', 'screening' => [['item' => 'tryingToConceive', 'is_on' => false]]]]);
    $refused = answerRefusal(F::atStep3(PhaseType::Maintenance)->with(step: 1), ['answers' => ['goal' => 'fatLoss', 'screening' => [['item' => 'tryingToConceive', 'is_on' => true]]]]);

    expect($draft->goal)->toBe(PhaseType::FatLoss)
        ->and($draft->screening)->toBe([])
        ->and($refused->field)->toBe('answers.goal');
});

// Step 2 (ONB-004)

it('ONB-004 saves name, sex, date of birth, height and weight', function () {
    $draft = answerDraft(F::atStep2(), ['answers' => [
        'name' => 'Alex', 'sex' => 'male', 'birth_date' => '1985-02-03',
        'height' => ['unit' => 'cm', 'cm' => 182.5], 'weight' => ['unit' => 'kg', 'value' => 90.5],
    ], 'move' => 'next']);

    expect($draft->step)->toBe(3)
        ->and($draft->name)->toBe('Alex')
        ->and($draft->sex)->toBe(Sex::Male)
        ->and($draft->birthDate)->toEqual(F::date(1985, 2, 3))
        ->and($draft->heightCm)->toBe(182.5)
        ->and($draft->weightKg)->toBe(90.5);
});

it('ONB-004 stores imperial entries in kg and cm and switches the units', function () {
    $draft = answerDraft(F::atStep2(), ['answers' => [
        'height' => ['unit' => 'feetInches', 'feet' => 5, 'inches' => 7.0], 'weight' => ['unit' => 'lb', 'value' => 176.0],
    ]]);

    expect($draft->heightUnit)->toBe(HeightUnit::FeetInches)
        ->and($draft->heightCm)->toBe(UnitConversion::feetInchesToCm(5, 7.0))
        ->and($draft->weightUnit)->toBe(WeightUnit::Lb)
        ->and($draft->weightKg)->toBe(UnitConversion::lbToKg(176.0));
});

it('ONB-004 a unit with no number only switches the unit and keeps the stored value', function () {
    $draft = answerDraft(F::atStep3(), ['answers' => ['height' => ['unit' => 'feetInches'], 'weight' => ['unit' => 'lb']]]);

    expect($draft->heightUnit)->toBe(HeightUnit::FeetInches)->and($draft->heightCm)->toBe(170.0)
        ->and($draft->weightUnit)->toBe(WeightUnit::Lb)->and($draft->weightKg)->toBe(80.0);
});

it('ONB-004 refuses Next on step 2 until every field is answered', function () {
    $refused = answerRefusal(F::atStep2(), ['answers' => ['name' => 'Sam'], 'move' => 'next']);

    expect($refused->field)->toBe('move')->and($refused->getMessage())->toBe('Fill in every field to continue.');
});

// Step 3 (SAFE-001, ONB-005)

it('SAFE-001 ticks and unticks screening items, each by name', function () {
    $ticked = answerDraft(F::atStep3(), ['answers' => ['screening' => [
        ['item' => 'diabetes', 'is_on' => true], ['item' => 'gallbladderDisease', 'is_on' => true],
    ]]]);
    $unticked = answerDraft($ticked, ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => false]]]]);

    expect($ticked->screening)->toBe([ScreeningItem::GallbladderDisease, ScreeningItem::Diabetes])
        ->and($unticked->screening)->toBe([ScreeningItem::GallbladderDisease]);
});

it('SAFE-001 an item measured from step 2 cannot be sent, and stays on whatever is unticked', function () {
    $measured = ['answers' => ['screening' => [['item' => 'ageOutOfRange', 'is_on' => true]]]];
    expect((new JsonSchemaValidator)->validate(OnboardingAnswerInput::jsonSchema(), $measured))->not->toBe([])
        ->and(answerDraft(F::atStep3(), $measured)->screening)->toBe([]);

    $minor = F::atStep3(PhaseType::Maintenance, F::minorBirthDate());
    $draft = answerDraft($minor, ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => false]]], 'move' => 'next']);

    expect($draft->screening)->toBe([])
        ->and($draft->step)->toBe(7);
});

it('ONB-005 a training-only answer on step 3 skips the nutrition steps, and Back skips them too', function () {
    $draft = answerDraft(F::atStep3(PhaseType::MuscleGain), ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => true]]], 'move' => 'next']);

    expect($draft->step)->toBe(7)
        ->and(answerDraft($draft, ['move' => 'back'])->step)->toBe(3)
        ->and(answerDraft($draft->with(step: 9, experience: ExperienceLevel::Beginner, trainingDays: F::trainingDays([2, 4]), trainingPlan: TrainingPlanKind::Later), ['move' => 'next'])->step)->toBe(12);
});

it('ONB-005 Fat loss while trying to conceive goes back to step 1 with the goal cleared', function () {
    $draft = answerDraft(F::atStep3(PhaseType::FatLoss), ['answers' => ['screening' => [['item' => 'tryingToConceive', 'is_on' => true]]], 'move' => 'next']);

    expect($draft->step)->toBe(1)
        ->and($draft->goal)->toBeNull()
        ->and($draft->screening)->toBe([ScreeningItem::TryingToConceive]);
});

it('ONB-005 trying to conceive with Muscle gain continues to step 4', function () {
    $draft = answerDraft(F::atStep3(PhaseType::MuscleGain), ['answers' => ['screening' => [['item' => 'tryingToConceive', 'is_on' => true]]], 'move' => 'next']);

    expect($draft->step)->toBe(4)->and($draft->goal)->toBe(PhaseType::MuscleGain);
});

// Steps 4 and 5 (ONB-006, SAFE-013, ONB-007)

it('ONB-006 SAFE-013 saves the GLP-1 answer, and Next needs one', function () {
    $atStep4 = F::atStep3()->with(step: 4);
    $refused = answerRefusal($atStep4, ['move' => 'next']);
    $draft = answerDraft($atStep4, ['answers' => ['uses_glp1' => true], 'move' => 'next']);

    expect($refused->getMessage())->toBe('Answer the question to continue.')
        ->and($draft->usesGLP1)->toBeTrue()
        ->and($draft->step)->toBe(5)
        ->and(answerDraft($draft, ['answers' => ['uses_glp1' => false]])->usesGLP1)->toBeFalse();
});

it('ONB-007 saves the body-fat bucket', function () {
    $draft = answerDraft(F::atStep3()->with(step: 5, usesGLP1: false), ['answers' => ['body_fat' => 'high'], 'move' => 'next']);

    expect($draft->bodyFatBucket)->toBe(BodyFatBucket::High)->and($draft->step)->toBe(6);
});

// Step 6 (ONB-008, ONB-009)

it('ONB-008 picking a different goal puts step 6 back to that goal\'s defaults and keeps the start', function () {
    $edited = F::atStep6()->with(ratePercentPerWeek: -0.5, customRate: true, weeks: 8, startDate: F::date(2026, 10, 20));
    $draft = answerDraft($edited, ['answers' => ['goal' => 'muscleGain']]);

    expect($draft->ratePercentPerWeek)->toBe(0.375)
        ->and($draft->customRate)->toBeFalse()
        ->and($draft->weeks)->toBe(12)
        ->and($draft->startDate)->toEqual(F::date(2026, 10, 20));
});

it('ONB-008 picking the same goal again keeps step 6 as it is', function () {
    $edited = F::atStep6()->with(ratePercentPerWeek: -0.5, customRate: true, weeks: 8);
    $draft = answerDraft($edited, ['answers' => ['goal' => 'fatLoss']]);

    expect($draft->ratePercentPerWeek)->toBe(-0.5)->and($draft->customRate)->toBeTrue()->and($draft->weeks)->toBe(8);
});

it('ONB-008 a first goal fills in the defaults and tomorrow', function () {
    $draft = answerDraft(new Draft, ['answers' => ['goal' => 'maintenance']]);

    expect($draft->ratePercentPerWeek)->toBe(0.0)->and($draft->weeks)->toBe(4)->and($draft->startDate)->toEqual(F::tomorrow());
});

it('ONB-008 a rate option sets its rate, and Custom keeps the rate and marks it custom', function () {
    $slow = answerDraft(F::atStep6(), ['answers' => ['rate_option' => 'Slow & steady']]);
    $custom = answerDraft($slow, ['answers' => ['rate_option' => OnboardingRateLength::CUSTOM_OPTION]]);
    $back = answerDraft($custom, ['answers' => ['rate_option' => 'Highest success']]);

    expect($slow->ratePercentPerWeek)->toBe(-0.5)->and($slow->customRate)->toBeFalse()
        ->and($custom->ratePercentPerWeek)->toBe(-0.5)->and($custom->customRate)->toBeTrue()
        ->and($back->ratePercentPerWeek)->toBe(-0.75)->and($back->customRate)->toBeFalse()
        ->and((new OnboardingRateLength($back, F::today()))->targetWeightKg())->toEqualWithDelta(72.8, 0.0001);
});

it('ONB-008 refuses a rate option the goal does not offer', function () {
    $refused = answerRefusal(F::atStep6(PhaseType::Maintenance), ['answers' => ['rate_option' => 'Slow & steady']]);

    expect($refused->field)->toBe('answers.rate_option');
});

it('ONB-008 a custom rate is read as the slider shows it, positive for fat loss, and moves only the target', function () {
    $draft = answerDraft(F::atStep6(), ['answers' => ['custom_rate' => 1.0]]);
    $rateLength = new OnboardingRateLength($draft, F::today());

    expect($draft->ratePercentPerWeek)->toBe(-1.0)
        ->and($draft->customRate)->toBeTrue()
        ->and($draft->weeks)->toBe(12)
        ->and($draft->startDate)->toEqual(F::tomorrow())
        ->and($rateLength->targetWeightKg())->toEqualWithDelta(70.4, 0.0001);
});

it('ONB-008 a new start date keeps the length and moves the end', function () {
    $draft = answerDraft(F::atStep6(), ['answers' => ['start_date' => '2026-10-14']]);

    expect($draft->weeks)->toBe(12)
        ->and((new OnboardingRateLength($draft, F::today()))->endDate())->toEqual(F::date(2027, 1, 6));
});

it('ONB-008 an end date recalculates the length, to the nearest week', function () {
    $eight = answerDraft(F::atStep6(), ['answers' => ['last_day' => '2026-12-05']]);
    $between = answerDraft(F::atStep6(), ['answers' => ['last_day' => '2026-11-04']]);

    expect($eight->weeks)->toBe(8)
        ->and($eight->startDate)->toEqual(F::tomorrow())
        ->and((new OnboardingRateLength($eight, F::today()))->targetWeightKg())->toEqualWithDelta(75.2, 0.0001)
        ->and($between->weeks)->toBe(4);
});

it('ONB-008 a start date and an end date sent together count the length from the new start', function () {
    $draft = answerDraft(F::atStep6(), ['answers' => ['start_date' => '2026-10-18', 'last_day' => '2026-12-12']]);

    expect($draft->startDate)->toEqual(F::date(2026, 10, 18))->and($draft->weeks)->toBe(8);
});

it('ONB-008 a target weight recalculates the length at the same rate, snapping to whole weeks', function () {
    $ten = answerDraft(F::atStep6(), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 74.0]]]);
    $snapped = answerDraft(F::atStep6(), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 73.5]]]);
    $pounds = answerDraft(F::atStep6(), ['answers' => ['target_weight' => ['unit' => 'lb', 'value' => 163.4]]]);
    $gain = answerDraft(F::atStep6(PhaseType::MuscleGain), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 82.0]]]);

    expect($ten->weeks)->toBe(10)->and($ten->ratePercentPerWeek)->toBe(-0.75)->and($ten->startDate)->toEqual(F::tomorrow())
        ->and($snapped->weeks)->toBe(11)
        ->and($pounds->weeks)->toBe(10)
        ->and($gain->weeks)->toBe(7);
});

it('ONB-008 a Hold rate leaves no target to move to, so a target weight changes nothing', function () {
    $draft = answerDraft(F::atStep6(PhaseType::Maintenance), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 75.0]]]);

    expect($draft->weeks)->toBe(4);
});

it('ONB-008 the length sets the weeks', function () {
    expect(answerDraft(F::atStep6(), ['answers' => ['weeks' => 8]])->weeks)->toBe(8);
});

it('ONB-009 clamps the length to TBL-010\'s limits', function (PhaseType $goal, int $weeks, int $kept) {
    expect(answerDraft(F::atStep6($goal), ['answers' => ['weeks' => $weeks]])->weeks)->toBe($kept);
})->with([
    'fat loss above 16' => [PhaseType::FatLoss, 30, 16],
    'fat loss below 2' => [PhaseType::FatLoss, 1, 2],
    'muscle gain below 4' => [PhaseType::MuscleGain, 2, 4],
    'muscle gain above 16' => [PhaseType::MuscleGain, 20, 16],
    'maintenance above 52' => [PhaseType::Maintenance, 100, 52],
]);

it('ONB-009 clamps an end date to the length limits', function () {
    expect(answerDraft(F::atStep6(), ['answers' => ['last_day' => '2027-11-30']])->weeks)->toBe(16)
        ->and(answerDraft(F::atStep6(), ['answers' => ['last_day' => '2026-10-11']])->weeks)->toBe(2);
});

it('ONB-009 clamps a target weight to the length limits: the wrong direction takes the shortest, a far one the longest', function () {
    expect(answerDraft(F::atStep6(), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 85.0]]])->weeks)->toBe(2)
        ->and(answerDraft(F::atStep6(PhaseType::MuscleGain), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 75.0]]])->weeks)->toBe(4)
        ->and(answerDraft(F::atStep6(), ['answers' => ['target_weight' => ['unit' => 'kg', 'value' => 60.0]]])->weeks)->toBe(16);
});

it('ONB-009 SAFE-005 clamps a custom rate to TBL-010\'s rate range', function (PhaseType $goal, float $slider, float $kept) {
    expect(answerDraft(F::atStep6($goal), ['answers' => ['custom_rate' => $slider]])->ratePercentPerWeek)->toBe($kept);
})->with([
    'fat loss slower than 0.25' => [PhaseType::FatLoss, 0.1, -0.25],
    'fat loss faster than 1.0' => [PhaseType::FatLoss, 1.5, -1.0],
    'muscle gain below 0.125' => [PhaseType::MuscleGain, 0.05, 0.125],
    'muscle gain above 0.5' => [PhaseType::MuscleGain, 0.9, 0.5],
    'maintenance above 0.25' => [PhaseType::Maintenance, 0.5, 0.25],
]);

it('ONB-008 refuses Next on step 6 until the rate, start and length are in', function () {
    $refused = answerRefusal(F::atStep3()->with(step: 6, usesGLP1: false, bodyFatBucket: BodyFatBucket::Low, weeks: null), ['move' => 'next']);

    expect($refused->getMessage())->toBe('Choose a rate and a length to continue.')
        ->and(answerDraft(F::atStep6(), ['move' => 'next'])->step)->toBe(7);
});

// Steps 7 to 9 (ONB-010 to ONB-012)

it('ONB-010 saves the training experience, and Next needs it', function () {
    $refused = answerRefusal(F::atStep7(), ['move' => 'next']);
    $draft = answerDraft(F::atStep7(), ['answers' => ['experience' => 'advanced'], 'move' => 'next']);

    expect($refused->getMessage())->toBe('Pick your training experience to continue.')
        ->and($draft->experience)->toBe(ExperienceLevel::Advanced)
        ->and($draft->step)->toBe(8);
});

it('ONB-011 a new training day starts at 18:00 for 60 minutes, and a saved day keeps its times', function () {
    $saved = F::atStep8()->with(trainingDays: [new TrainingDayChoice(3, 6 * 60, 45)]);
    $draft = answerDraft($saved, ['answers' => ['training_days' => [['weekday' => 5], ['weekday' => 3], ['weekday' => 1, 'start_minutes' => 420]]]]);

    expect($draft->trainingDays)->toEqual([
        new TrainingDayChoice(1, 420, 60), new TrainingDayChoice(3, 6 * 60, 45), new TrainingDayChoice(5, 18 * 60, 60),
    ]);
});

it('ONB-011 each day takes its own time and length, inside the day and the length limits', function () {
    $draft = answerDraft(F::atStep9(), ['answers' => ['training_days' => [
        ['weekday' => 1, 'start_minutes' => 1439, 'length_minutes' => 400], ['weekday' => 3, 'length_minutes' => 5], ['weekday' => 3, 'length_minutes' => 90],
    ]]]);

    expect($draft->trainingDays)->toEqual([new TrainingDayChoice(1, 1439, 300), new TrainingDayChoice(3, 18 * 60, 90)]);
});

it('ONB-011 an empty list removes every day, and Next needs one', function () {
    $draft = answerDraft(F::atStep9()->with(step: 8), ['answers' => ['training_days' => []]]);

    expect($draft->trainingDays)->toBe([])
        ->and(answerRefusal($draft, ['move' => 'next'])->getMessage())->toBe('Pick at least one training day to continue.');
});

it('ONB-012 picking a template, the Meso Builder or "I\'ll build it later" sets the plan kind', function () {
    $template = answerDraft(F::atStep9(), ['answers' => ['training_plan' => 'template:2'], 'move' => 'next']);
    $builder = answerDraft($template, ['answers' => ['training_plan' => 'meso_builder']]);
    $later = answerDraft($template, ['answers' => ['training_plan' => 'later']]);

    expect($template->trainingPlan)->toBe(TrainingPlanKind::Template)->and($template->templateId)->toBe(2)->and($template->step)->toBe(10)
        ->and($builder->trainingPlan)->toBe(TrainingPlanKind::MesoBuilder)->and($builder->templateId)->toBeNull()
        ->and($later->trainingPlan)->toBe(TrainingPlanKind::Later)->and($later->templateId)->toBeNull();
});

it('ONB-012 refuses a template that does not fit the answers, or a plan that is not offered', function () {
    $twoDays = F::atStep9(days: [1, 4]);

    expect(answerRefusal($twoDays, ['answers' => ['training_plan' => 'template:2']])->field)->toBe('answers.training_plan')
        ->and(answerRefusal(F::atStep9(PhaseType::MuscleGain), ['answers' => ['training_plan' => 'template:2']])->field)->toBe('answers.training_plan')
        ->and(answerRefusal(F::atStep9(), ['answers' => ['training_plan' => 'template:99']])->field)->toBe('answers.training_plan')
        ->and(answerRefusal(F::atStep9(), ['answers' => ['training_plan' => 'someday']])->field)->toBe('answers.training_plan');
});

it('ONB-012 a template picked with new training days in the same answer fits those days', function () {
    $draft = answerDraft(F::atStep8(), ['answers' => ['training_days' => [['weekday' => 2], ['weekday' => 4], ['weekday' => 6]], 'training_plan' => 'template:2']]);

    expect($draft->templateId)->toBe(2);
});

// Steps 10 to 13 (ONB-013 to ONB-017)

it('ONB-013 saves the step ranges, and a minimum above the maximum raises the maximum', function () {
    $draft = answerDraft(F::atStep10(), ['answers' => ['step_ranges' => [
        ['weekday' => 6, 'min' => 8000, 'max' => 13000], ['weekday' => 1, 'min' => 9000, 'max' => 4000],
    ]], 'move' => 'next']);

    expect($draft->stepRanges)->toEqual([new StepRangeChoice(1, 9000, 9000), new StepRangeChoice(6, 8000, 13000)])
        ->and($draft->step)->toBe(11);
});

it('ONB-014 saves the eating window, the meal count and the busy periods', function () {
    $saved = F::atStep11()->with(busyPeriods: [new BusyPeriodChoice('gym', 'Gym', 17 * 60, 18 * 60, [1])]);
    $draft = answerDraft($saved, ['answers' => [
        'first_meal_minutes' => 8 * 60, 'last_meal_minutes' => 21 * 60, 'meal_count' => 4,
        'busy_periods' => [
            ['id' => 'gym', 'label' => '  ', 'start_minutes' => 17 * 60, 'end_minutes' => 19 * 60, 'weekdays' => [3, 1, 3]],
            ['label' => 'Meeting', 'start_minutes' => 12 * 60, 'end_minutes' => 13 * 60, 'weekdays' => [2]],
        ],
    ], 'move' => 'next']);

    expect($draft->eatingStartMinutes)->toBe(8 * 60)
        ->and($draft->eatingEndMinutes)->toBe(21 * 60)
        ->and($draft->mealCount)->toBe(4)
        ->and($draft->busyPeriods)->toEqual([
            new BusyPeriodChoice('gym', 'Gym', 17 * 60, 19 * 60, [1, 3]),
            new BusyPeriodChoice('new-1', 'Meeting', 12 * 60, 13 * 60, [2]),
        ])
        ->and($draft->step)->toBe(12);
});

it('ONB-014 refuses Next when the eating window cannot hold the meals', function () {
    $refused = answerRefusal(F::atStep11(), ['answers' => ['first_meal_minutes' => 12 * 60, 'last_meal_minutes' => 13 * 60, 'meal_count' => 6], 'move' => 'next']);

    expect($refused->field)->toBe('move')->and($refused->getMessage())->toBe(OnboardingLifestyle::MEALS_DO_NOT_FIT_HINT);
});

it('ONB-015 a new weigh-in day starts at 07:00, and a saved day keeps its time', function () {
    $saved = F::atStep12()->with(weighIns: [new WeighInChoice(3, 6 * 60)]);
    $draft = answerDraft($saved, ['answers' => ['weigh_ins' => [['weekday' => 5], ['weekday' => 3], ['weekday' => 1, 'minutes' => 400]]], 'move' => 'next']);

    expect($draft->weighIns)->toEqual([new WeighInChoice(1, 400), new WeighInChoice(3, 6 * 60), new WeighInChoice(5, 7 * 60)])
        ->and($draft->step)->toBe(13);
});

it('ONB-015 refuses Next with fewer than 2 weigh-in days', function () {
    $refused = answerRefusal(F::atStep12(), ['answers' => ['weigh_ins' => [['weekday' => 1]]], 'move' => 'next']);

    expect($refused->field)->toBe('move')->and($refused->getMessage())->toBe('Too few weigh-ins to continue');
});

it('ONB-016 saves the review day', function () {
    expect(answerDraft(F::atStep13(), ['answers' => ['review_weekday' => '2']])->reviewWeekday)->toBe(2);
});

it('ONB-017 choosing my own calories starts from the recommended average, and the recommendation drops it', function () {
    $recommended = (new OnboardingCalories(F::atStep13(), F::today(), []))->recommended();
    assert($recommended !== null);
    $own = answerDraft(F::atStep13(), ['answers' => ['calories' => 'own']]);
    $chosen = (int) round($recommended->averageKcal) + 100;
    $typed = answerDraft($own, ['answers' => ['own_kcal' => $chosen], 'move' => 'next']);
    $back = answerDraft($typed, ['answers' => ['calories' => 'recommendation']]);

    expect($own->choosesOwnCalories)->toBeTrue()
        ->and($own->ownDailyKcal)->toBe((int) round($recommended->averageKcal))
        ->and($typed->ownDailyKcal)->toBe($chosen)
        ->and($typed->step)->toBe(OnboardingSteps::REVIEW_STEP)
        ->and($back->choosesOwnCalories)->toBeFalse()
        ->and($back->ownDailyKcal)->toBeNull();
});

it('ONB-017 refuses Next while the calories are not ready, with step 13\'s message', function () {
    $refused = answerRefusal(F::atStep13()->with(choosesOwnCalories: true), ['move' => 'next']);

    expect($refused->field)->toBe('move')->and($refused->getMessage())->toBe(OnboardingCalories::OWN_CALORIES_MESSAGE);
});

// The review (ONB-018, ONB-020)

it('ONB-018 an Edit link goes back to a step that is shown, and keeps every answer', function () {
    $draft = answerDraft(F::atReview(), ['move' => 'edit', 'edit_step' => 6]);

    expect($draft->step)->toBe(6)->and($draft->with(step: 14))->toEqual(F::atReview());
});

it('ONB-018 refuses an Edit to a step that is not shown, or with no step', function () {
    $hidden = answerRefusal(F::atTrainingOnlyReview(), ['move' => 'edit', 'edit_step' => 4]);
    $missing = answerRefusal(F::atReview(), ['move' => 'edit']);

    expect($hidden->field)->toBe('edit_step')->and($missing->field)->toBe('edit_step');
});

it('ONB-020 entering the review moves a start date in the past to tomorrow and keeps the length', function () {
    $stale = F::atTrainingOnlyReview()->with(step: 12, startDate: F::date(2026, 10, 1));
    $draft = answerDraft($stale, ['move' => 'next']);

    expect($draft->step)->toBe(OnboardingSteps::REVIEW_STEP)
        ->and($draft->startDate)->toEqual(F::tomorrow())
        ->and($draft->weeks)->toBe($stale->weeks);
});

it('ONB-018 Next on the review saves and stays: "Start my plan" is onboarding_complete', function () {
    expect(answerDraft(F::atReview(), ['move' => 'next'])->step)->toBe(OnboardingSteps::REVIEW_STEP);
});
