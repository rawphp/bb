<?php

use App\Domain\Billing\Access;
use App\Domain\Billing\BillingRule;

// The AI scans are reads, but each one spends the instance's AI key, so they come with the plan (SUB-001).

it('SUB-001 a trial or a subscription unlocks the AI scans', function (Access $access, string $scan) {
    expect(BillingRule::allows($access, $scan, readOnly: true))->toBeTrue();
})->with(fn () => [Access::Trial, Access::Active])->with(BillingRule::AI_READS);

it('SUB-010 SUB-022 a lapsed user keeps every screen but not the AI scans', function (string $scan) {
    expect(BillingRule::allows(Access::Lapsed, $scan, readOnly: true))->toBeFalse()
        ->and(BillingRule::allows(Access::Lapsed, 'scan_adjust', readOnly: true))->toBeTrue()
        ->and(BillingRule::allows(Access::Lapsed, 'food_barcode_lookup', readOnly: true))->toBeTrue();
})->with(BillingRule::AI_READS);

it('SUB-003 no free tier: a user who has never subscribed gets no AI scan', function (string $scan) {
    expect(BillingRule::allows(Access::None, $scan, readOnly: true))->toBeFalse();
})->with(BillingRule::AI_READS);

it('SUB-001 RCP-003 RCP-010 the AI reads are the scans, generating a recipe and importing one', function () {
    expect(BillingRule::AI_READS)->toEqualCanonicalizing(['scan_photo', 'scan_describe', 'scan_label', 'recipe_generate', 'recipe_import']);
});

it('SUB-010 SUB-022 a capability needs no plan when it is a read other than an AI scan, or billing itself', function () {
    expect(BillingRule::isFree('today_show', readOnly: true))->toBeTrue()
        ->and(BillingRule::isFree('scan_adjust', readOnly: true))->toBeTrue()
        ->and(BillingRule::isFree('billing_checkout_start', readOnly: false))->toBeTrue()
        ->and(BillingRule::isFree('scan_photo', readOnly: true))->toBeFalse()
        ->and(BillingRule::isFree('meal_check_in', readOnly: false))->toBeFalse();
});
