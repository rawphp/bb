<?php

use App\Domain\Billing\Access;

it('SUB-010 a user Stripe has never billed has no subscription', function () {
    expect(Access::fromStripeStatus(null))->toBe(Access::None);
});

it('SUB-003 a trialing subscription is the free trial', function () {
    expect(Access::fromStripeStatus('trialing'))->toBe(Access::Trial);
});

it('SUB-010 an active subscription, or one Stripe is still retrying, keeps access', function (string $status) {
    expect(Access::fromStripeStatus($status))->toBe(Access::Active);
})->with(['active', 'past_due']);

it('SUB-010 a subscription that ended, went unpaid or paused has lapsed', function (string $status) {
    expect(Access::fromStripeStatus($status))->toBe(Access::Lapsed);
})->with(['canceled', 'unpaid', 'incomplete_expired', 'paused']);

it('SUB-010 a first payment still being taken is not a subscription yet', function () {
    expect(Access::fromStripeStatus('incomplete'))->toBe(Access::None);
});

it('SUB-010 a status Stripe adds later counts as lapsed, never as paid', function () {
    expect(Access::fromStripeStatus('something_new'))->toBe(Access::Lapsed);
});
