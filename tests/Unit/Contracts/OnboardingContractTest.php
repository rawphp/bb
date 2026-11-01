<?php

use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
use App\Capabilities\Onboarding\Data\OnboardingAnswers;
use App\Capabilities\Onboarding\Data\OnboardingScreeningAnswer;
use App\Capabilities\Onboarding\Data\OnboardingStatus;
use App\Capabilities\Support\Tone;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

$names = ['onboarding_show', 'onboarding_answer', 'onboarding_complete'];

it('ONB-002 maps every onboarding read and write to the API issue that registers it', function () use ($names) {
    $owners = json_decode((string) file_get_contents(base_path('tests/fixtures/schemas/onboarding.owners.json')), true);

    expect(array_keys($owners))->toEqualCanonicalizing($names)
        ->and($owners)->toBe(['onboarding_show' => 63, 'onboarding_answer' => 67, 'onboarding_complete' => 63]);
});

it('ONB-002 registers nothing: the API issues do', function () use ($names) {
    $registry = app(CapabilityRegistry::class);

    foreach ($names as $name) {
        expect($registry->has($name))->toBeFalse("{$name} is registered");
    }
    expect(glob(app_path('Capabilities/Onboarding/*Capability.php')))->toBe([]);
});

it('CAP-018 lets a status carry only the tones the server picks from', function () {
    expect(OnboardingStatus::jsonSchema()['properties']['tone']['enum'])
        ->toBe(array_column(Tone::cases(), 'value'));
});

it('SAFE-001 takes only the screening items a person ticks; age, height, weight and BMI come from step 2', function () {
    expect(OnboardingScreeningAnswer::jsonSchema()['properties']['item']['enum'])->toBe([
        'pregnantOrBreastfeeding',
        'tryingToConceive',
        'transgenderOnHormoneTherapy',
        'heartThyroidOrPCOS',
        'weightLossSurgery',
        'gallbladderDisease',
        'autoimmuneDisease',
        'diabetes',
        'eatingDisorderHistory',
    ]);
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
