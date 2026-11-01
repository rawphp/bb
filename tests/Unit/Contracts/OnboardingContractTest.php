<?php

use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
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

it('AGT-031 records an answer without moving, so the agent can answer a few at a time', function () {
    $input = OnboardingAnswerInput::fromArray(['answers' => ['goal' => 'fatLoss']]);

    expect($input->move)->toBe('stay')
        ->and($input->answers->goal)->toBe('fatLoss')
        ->and($input->answers->name)->toBeNull();
});
