<?php

use App\Actions\Billing\OpenPortal;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rawphp\Capabilities\Support\CapabilityFailure;
use Tests\Support\FakeStripe;

// The action reads the account's Stripe customer from subscriptions, so it runs on the database.
uses(RefreshDatabase::class);

it('SUB-007 refuses an account that opened Checkout but never subscribed', function () {
    $stripe = new FakeStripe;
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();

    expect(fn () => (new OpenPortal($stripe))($user))->toThrow(CapabilityFailure::class, 'There is no subscription to manage yet.');
    expect($stripe->portals)->toBe([]);
});

it('SUB-007 opens the portal for an account whose subscription has ended, so it can resubscribe there too', function () {
    $stripe = new FakeStripe;
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['stripe_customer_id' => 'cus_mine', 'status' => 'canceled']);

    expect((new OpenPortal($stripe))($user))->toBe('https://billing.stripe.com/p/session/test_1');
});
