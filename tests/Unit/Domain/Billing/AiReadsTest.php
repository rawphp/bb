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

it('SUB-001 the AI scans are scan_photo, scan_describe and scan_label', function () {
    expect(BillingRule::AI_READS)->toEqualCanonicalizing(['scan_photo', 'scan_describe', 'scan_label']);
});
