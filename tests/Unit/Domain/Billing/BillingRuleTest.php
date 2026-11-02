<?php

use App\Domain\Billing\Access;
use App\Domain\Billing\BillingRule;

it('SUB-010 a lapsed user can still read everything', function (string $read) {
    expect(BillingRule::allows(Access::Lapsed, $read, readOnly: true))->toBeTrue();
})->with(['today_show', 'billing_show', 'activity_show']);

it('SUB-022 a lapsed user cannot write', function (string $write) {
    expect(BillingRule::allows(Access::Lapsed, $write, readOnly: false))->toBeFalse();
})->with(['meal_check_in', 'workout_set_log', 'token_create', 'undo_apply', 'onboarding_complete']);

it('SUB-022 a lapsed user can still delete the account and pay', function (string $write) {
    expect(BillingRule::allows(Access::Lapsed, $write, readOnly: false))->toBeTrue();
})->with(['account_delete', 'billing_checkout_start', 'billing_portal_open']);

it('SUB-022 a lapsed user can still withdraw access and consent: revoke a token, stop push', function (string $write) {
    expect(BillingRule::allows(Access::Lapsed, $write, readOnly: false))->toBeTrue();
})->with(['token_revoke', 'push_unsubscribe']);

it('SUB-001 a trial or a subscription unlocks every write', function (Access $access, string $write) {
    expect(BillingRule::allows($access, $write, readOnly: false))->toBeTrue();
})->with(fn () => [Access::Trial, Access::Active])->with(['meal_check_in', 'onboarding_complete', 'token_create']);

it('SUB-003 the first plan waits for the trial: onboarding_complete needs a trial or a subscription', function () {
    expect(BillingRule::allows(Access::None, 'onboarding_complete', readOnly: false))->toBeFalse()
        ->and(BillingRule::allows(Access::Trial, 'onboarding_complete', readOnly: false))->toBeTrue();
});

it('SUB-004 a new user can answer onboarding and reach the paywall before paying', function (string $write) {
    expect(BillingRule::allows(Access::None, $write, readOnly: false))->toBeTrue();
})->with(['onboarding_answer', 'billing_checkout_start', 'account_delete']);

it('SUB-024 the Coach is part of the plan: a trial or a subscription gets it, a lapsed user does not', function () {
    expect(BillingRule::allowsCoach(Access::Trial))->toBeTrue()
        ->and(BillingRule::allowsCoach(Access::Active))->toBeTrue()
        ->and(BillingRule::allowsCoach(Access::Lapsed))->toBeFalse();
});

it('SUB-024 a user who has never subscribed keeps the Coach, so chat onboarding works before the paywall (AGT-030)', function () {
    expect(BillingRule::allowsCoach(Access::None))->toBeTrue();
});
