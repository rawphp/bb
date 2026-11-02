<?php

use App\Capabilities\Reads\Settings\EligibilityRecheckPayload;
use App\Domain\Safety\ScreeningItem;
use App\Models\Profile;

// SAFE-017: Settings → Re-check eligibility shows the screening again, prefilled, with the current mode. No database:
// the profile is built in memory. The HTTP seam and the fixtures are EligibilityRecheckTest's.

function recheckProfile(array $values = []): Profile
{
    return new Profile(array_replace([
        'birth_date' => '1990-04-12', 'height_cm' => 168.0, 'weight_kg' => 64.0,
        'screening' => [], 'training_only' => false,
    ], $values));
}

function recheckShow(Profile $profile, string $today = '2026-10-14'): array
{
    return EligibilityRecheckPayload::build($profile, new DateTimeImmutable($today, new DateTimeZone('UTC')))->toArray();
}

it('SAFE-017 SAFE-001 lists every screening item in the spec\'s order', function () {
    $items = array_column(recheckShow(recheckProfile())['questions'], 'item');

    expect($items)->toBe(array_map(fn (ScreeningItem $item) => $item->value, ScreeningItem::cases()));
});

it('SAFE-017 prefills the saved ticked items and works the measured ones out from the profile again', function () {
    // Saved at setup with an age that no longer applies; age is worked out from the birth date, not carried over.
    $show = recheckShow(recheckProfile(['screening' => ['ageOutOfRange', 'diabetes'], 'training_only' => true]));

    $applies = array_column(array_filter($show['questions'], fn (array $q) => $q['applies']), 'item');
    expect($applies)->toBe(['diabetes']);
});

it('SAFE-017 marks age, height, weight and BMI as measured, so they cannot be ticked', function () {
    $measured = array_column(array_filter(recheckShow(recheckProfile())['questions'], fn (array $q) => $q['measured']), 'item');

    expect($measured)->toBe(['ageOutOfRange', 'heightOutOfRange', 'weightOverLimit', 'bmiUnderLimit']);
});

it('SAFE-017 WT-011 a measured item applies from the profile\'s stored weight', function () {
    // 40 kg at 168 cm is a BMI under 17.
    $bmi = collect(recheckShow(recheckProfile(['weight_kg' => 40.0]))['questions'])->firstWhere('item', 'bmiUnderLimit');

    expect($bmi)->toBe(['item' => 'bmiUnderLimit', 'label' => 'Your BMI is under 17', 'applies' => true, 'measured' => true]);
});

it('SAFE-017 each question reads as the screening item it asks about', function () {
    $labels = array_column(recheckShow(recheckProfile())['questions'], 'label', 'item');

    expect($labels['pregnantOrBreastfeeding'])->toBe('You are pregnant or breastfeeding')
        ->and($labels['ageOutOfRange'])->toBe('You are under 18 or over 65');
});

it('SAFE-017 a nutrition user is on training and nutrition, with no reasons', function () {
    $show = recheckShow(recheckProfile());

    expect($show['mode'])->toBe(['text' => 'Training and nutrition', 'tone' => 'good'])
        ->and($show['reasons'])->toBe([]);
});

it('SAFE-017 SAFE-003 a training-only user sees why, one reason for each item that applied', function () {
    $show = recheckShow(recheckProfile(['screening' => ['bmiUnderLimit', 'pregnantOrBreastfeeding'], 'training_only' => true]));

    expect($show['mode'])->toBe(['text' => 'Training only', 'tone' => 'warning'])
        ->and($show['reasons'])->toBe([
            ['text' => 'Your BMI is under 17, so the app does not set calorie targets.'],
            ['text' => 'You are pregnant or breastfeeding, so the app does not set calorie targets.'],
        ]);
});

it('SAFE-017 ONB-005 a training-only user whose answers now pass is told the nutrition steps are what is left', function () {
    $show = recheckShow(recheckProfile(['screening' => [], 'training_only' => true]));

    expect($show['mode'])->toBe(['text' => 'Training only', 'tone' => 'warning'])
        ->and($show['reasons'])->toBe([['text' => 'Your answers allow nutrition targets now. Finish the nutrition steps to turn them on.']]);
});

it('SAFE-001 SAFE-017 a user trying to conceive keeps nutrition targets without Fat loss', function () {
    $show = recheckShow(recheckProfile(['screening' => ['tryingToConceive']]));

    expect($show['mode'])->toBe(['text' => 'Training and nutrition, no fat loss', 'tone' => 'info'])
        ->and($show['reasons'])->toBe([]);
});

it('SAFE-001 SAFE-017 saved items that block every target read as training only, whatever the stored mode', function () {
    $show = recheckShow(recheckProfile(['screening' => ['tryingToConceive', 'diabetes'], 'training_only' => false]));

    expect($show['mode'])->toBe(['text' => 'Training only', 'tone' => 'warning']);
});
