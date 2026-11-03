<?php

use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
use App\Capabilities\Onboarding\Data\OnboardingAnswers;
use App\Capabilities\Onboarding\Data\OnboardingBodyFatSection;
use App\Capabilities\Onboarding\Data\OnboardingHeight;
use App\Capabilities\Onboarding\Data\OnboardingHeightAnswer;
use App\Capabilities\Onboarding\Data\OnboardingReviewBlock;
use App\Capabilities\Onboarding\Data\OnboardingScreeningAnswer;
use App\Capabilities\Onboarding\Data\OnboardingStepRangeAnswer;
use App\Capabilities\Onboarding\Data\OnboardingTargetWeight;
use App\Capabilities\Onboarding\Data\OnboardingWeight;
use App\Capabilities\Onboarding\Data\OnboardingWeightAnswer;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

$names = ['onboarding_show', 'onboarding_answer', 'onboarding_complete'];

it('ONB-002 maps every onboarding read and write to the API issue that registers it', function () use ($names) {
    $owners = json_decode((string) file_get_contents(base_path('tests/fixtures/schemas/onboarding.owners.json')), true);

    expect(array_keys($owners))->toEqualCanonicalizing($names)
        ->and($owners)->toBe(['onboarding_show' => 63, 'onboarding_answer' => 67, 'onboarding_complete' => 63]);
});

it('ONB-002 registers every onboarding read and write from its own class file', function () use ($names) {
    $registry = app(CapabilityRegistry::class);
    $files = array_map('basename', glob(app_path('Capabilities/Onboarding/*Capability.php')) ?: []);

    foreach ($names as $name) {
        expect($registry->has($name))->toBeTrue("{$name} is not registered")
            ->and($files)->toContain(str_replace('_', '', ucwords($name, '_')).'Capability.php');
    }
});

it('SAFE-001 takes only the screening items a person ticks; age, height, weight and BMI come from step 2', function () {
    $measured = array_column(Screening::MEASURED_KINDS, 'value');
    $ticked = array_values(array_diff(array_column(ScreeningItem::cases(), 'value'), $measured));

    expect(OnboardingScreeningAnswer::jsonSchema()['properties']['item']['enum'])->toBe($ticked);
});

it('SAFE-001 lists every screening item in the spec\'s order, in every state of the screen', function () {
    foreach (glob(base_path('tests/fixtures/screens/onboarding_show*.json')) ?: [] as $path) {
        $rows = json_decode((string) file_get_contents($path), true)['screening']['rows'];

        expect(array_column($rows, 'item'))->toBe(array_column(ScreeningItem::cases(), 'value'), basename($path));
    }
});

// The Field attribute takes only literals, so each copied list is pinned to its domain enum here.
it('ONB-003 ONB-004 ONB-007 ONB-010 CAP-015 keep every copied choice list equal to its domain enum', function (string $dto, string $field, array $values) {
    $schema = $dto::jsonSchema()['properties'][$field];

    expect($schema['enum'])->toBe($values);
})->with([
    'goal' => [OnboardingAnswers::class, 'goal', ['fatLoss', 'muscleGain', 'maintenance']],
    'sex' => [OnboardingAnswers::class, 'sex', array_column(Sex::cases(), 'value')],
    'body fat' => [OnboardingAnswers::class, 'body_fat', array_column(BodyFatBucket::cases(), 'value')],
    'experience' => [OnboardingAnswers::class, 'experience', array_column(ExperienceLevel::cases(), 'value')],
    'body fat sex' => [OnboardingBodyFatSection::class, 'sex', [...array_column(Sex::cases(), 'value'), null]],
    'height unit' => [OnboardingHeight::class, 'unit', array_column(HeightUnit::cases(), 'value')],
    'height answer unit' => [OnboardingHeightAnswer::class, 'unit', array_column(HeightUnit::cases(), 'value')],
    'weight unit' => [OnboardingWeight::class, 'unit', array_column(WeightUnit::cases(), 'value')],
    'weight answer unit' => [OnboardingWeightAnswer::class, 'unit', array_column(WeightUnit::cases(), 'value')],
    'target weight unit' => [OnboardingTargetWeight::class, 'unit', array_column(WeightUnit::cases(), 'value')],
]);

it('ONB-003 offers every phase type as a goal except the Mini-cut', function () {
    $goals = OnboardingAnswers::jsonSchema()['properties']['goal']['enum'];
    $phases = array_column(PhaseType::cases(), 'value');

    expect($goals)->toEqualCanonicalizing(array_values(array_diff($phases, [PhaseType::MiniCut->value])));
});

it('SAFE-001 locks every screening row the answer cannot send, in every state of the screen', function () {
    $sendable = OnboardingScreeningAnswer::jsonSchema()['properties']['item']['enum'];

    foreach (glob(base_path('tests/fixtures/screens/onboarding_show*.json')) ?: [] as $path) {
        $rows = json_decode((string) file_get_contents($path), true)['screening']['rows'];

        expect(array_column($rows, 'item'))->toContain(...$sendable);
        foreach ($rows as $row) {
            expect($row['is_locked'])->toBe(! in_array($row['item'], $sendable, true), basename($path).' '.$row['item']);
        }
    }
});

it('ONB-016 takes the review day back as the value of the review_days choice the show returned', function () {
    $accepted = OnboardingAnswers::jsonSchema()['properties']['review_weekday']['enum'] ?? [];

    foreach (glob(base_path('tests/fixtures/screens/onboarding_show*.json')) ?: [] as $path) {
        $values = array_column(json_decode((string) file_get_contents($path), true)['weigh_ins']['review_days'], 'value');

        expect($values)->toHaveCount(7)
            ->and($accepted)->toBe($values, basename($path));
    }
});

it('AGT-031 records an answer without moving, so the agent can answer a few at a time', function () {
    $input = OnboardingAnswerInput::fromArray(['answers' => ['goal' => 'fatLoss']]);

    expect($input->move)->toBe('stay')
        ->and($input->answers->goal)->toBe('fatLoss')
        ->and($input->answers->name)->toBeNull();
});

it('ONB-013 ONB-017 ONB-018 accepts back every bound the show advertises, in every state of the screen', function () {
    $answers = OnboardingAnswers::jsonSchema()['properties'];
    $range = OnboardingStepRangeAnswer::jsonSchema()['properties'];
    $editStep = OnboardingAnswerInput::jsonSchema()['properties']['edit_step'];

    foreach (glob(base_path('tests/fixtures/screens/onboarding_show*.json')) ?: [] as $path) {
        $show = json_decode((string) file_get_contents($path), true);

        expect($answers['own_kcal']['maximum'] ?? null)->toBe($show['calories']['own_kcal_max'], basename($path))
            ->and($range['min']['maximum'] ?? null)->toBe($show['steps']['max_steps'], basename($path))
            ->and($range['max']['maximum'] ?? null)->toBe($show['steps']['max_steps'], basename($path));
        foreach ($show['review']['sections'] as $block) {
            expect($block['edit_step'])->toBeGreaterThanOrEqual($editStep['minimum'])->toBeLessThanOrEqual($editStep['maximum']);
        }
    }

    expect(OnboardingReviewBlock::jsonSchema()['properties']['edit_step'])
        ->toMatchArray(['minimum' => $editStep['minimum'], 'maximum' => $editStep['maximum']]);
});

it('ONB-007 leaves body_fat.sex null until step 2 has one, while the step shows the female buckets', function () {
    $empty = json_decode((string) file_get_contents(base_path('tests/fixtures/screens/onboarding_show.empty.json')), true);

    expect(array_filter(array_column($empty['about_you']['sex_options'], 'is_selected')))->toBe([])
        ->and($empty['body_fat']['sex'])->toBeNull()
        ->and($empty['body_fat']['options'])->not->toBeEmpty()
        ->and(OnboardingBodyFatSection::jsonSchema()['properties']['sex']['type'])->toBe(['string', 'null']);
});
