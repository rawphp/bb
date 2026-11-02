<?php

use App\Actions\Billing\StartCheckout;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Rawphp\Capabilities\Support\CapabilityFailure;
use Tests\Support\FakeStripe;

// The action's job is the Stripe customer it keeps in subscriptions, so it runs on the database.
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.prices' => ['monthly' => 'price_monthly', 'six_months' => null, 'yearly' => 'price_yearly']]);
    $this->stripe = new FakeStripe;
});

it('SUB-003 opens Checkout with the 7-day trial for an account Stripe has never billed', function () {
    $user = User::factory()->create();

    expect((new StartCheckout($this->stripe))($user, 'yearly'))->toBe('https://checkout.stripe.com/c/pay/cs_test_1')
        ->and($this->stripe->checkouts[0])->toMatchArray(['customer' => 'cus_test_1', 'price' => 'price_yearly', 'trial_days' => 7]);
});

it('SUB-003 keeps the trial for an account that opened Checkout before but never subscribed', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['stripe_customer_id' => 'cus_abandoned']);

    (new StartCheckout($this->stripe))($user, 'monthly');

    expect($this->stripe->customers)->toBe([])
        ->and($this->stripe->checkouts[0])->toMatchArray(['customer' => 'cus_abandoned', 'trial_days' => 7]);
});

it('SUB-007 refuses a second subscription while one is live: plans switch in the portal', function (string $status) {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => $status]);

    expect(fn () => (new StartCheckout($this->stripe))($user, 'yearly'))
        ->toThrow(CapabilityFailure::class, 'You already have a subscription. Switch plans in Manage subscription.');
    expect($this->stripe->checkouts)->toBe([]);
})->with(['trialing', 'active', 'past_due']);

it('SUB-002 refuses a plan the instance does not sell, before calling Stripe', function () {
    expect(fn () => (new StartCheckout($this->stripe))(User::factory()->create(), 'six_months'))
        ->toThrow(CapabilityFailure::class, 'That plan is not on sale.');
    expect($this->stripe->customers)->toBe([])
        ->and($this->stripe->checkouts)->toBe([]);
});

it('SUB-007 asks Stripe before selling a second subscription: one Stripe holds counts before its webhook arrives', function () {
    $user = User::factory()->create();
    $row = Subscription::factory()->for($user)->create(['stripe_customer_id' => 'cus_paid']);
    $this->stripe->subscriptions['cus_paid'] = [['id' => 'sub_1', 'customer' => 'cus_paid', 'status' => 'trialing', 'created' => 1_793_000_000]];

    expect(fn () => (new StartCheckout($this->stripe))($user, 'yearly'))
        ->toThrow(CapabilityFailure::class, 'You already have a subscription. Switch plans in Manage subscription.');
    expect($this->stripe->checkouts)->toBe([])
        ->and($row->refresh()->status)->toBe('trialing');
});

it('SUB-003 gives no second trial to a customer Stripe has billed, even when its webhook never arrived', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['stripe_customer_id' => 'cus_before']);
    $this->stripe->subscriptions['cus_before'] = [['id' => 'sub_1', 'customer' => 'cus_before', 'status' => 'canceled', 'created' => 1_700_000_000]];

    (new StartCheckout($this->stripe))($user, 'monthly');

    expect($this->stripe->checkouts[0])->toMatchArray(['customer' => 'cus_before', 'trial_days' => null]);
});

it('SUB-007 closes the Checkout page it opened before, so only one can be paid', function () {
    $user = User::factory()->create();

    (new StartCheckout($this->stripe))($user, 'monthly');
    $url = (new StartCheckout($this->stripe))($user, 'yearly');

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_2')
        ->and($this->stripe->closed)->toBe(['cs_test_1'])
        ->and(Subscription::of($user)?->checkout_session_id)->toBe('cs_test_2');
});

it('SUB-007 refuses a new Checkout when the page opened before was paid and Stripe has not listed it yet', function () {
    $user = User::factory()->create();
    (new StartCheckout($this->stripe))($user, 'monthly');
    $this->stripe->paid = ['cs_test_1'];

    expect(fn () => (new StartCheckout($this->stripe))($user, 'yearly'))
        ->toThrow(CapabilityFailure::class, 'You already have a subscription. Switch plans in Manage subscription.');
    expect($this->stripe->checkouts)->toHaveCount(1);
});

it('SUB-010 a user whose paid Checkout has since ended can resubscribe', function () {
    $user = User::factory()->create();
    (new StartCheckout($this->stripe))($user, 'monthly');
    $this->stripe->paid = ['cs_test_1'];
    $customer = (string) Subscription::of($user)?->stripe_customer_id;
    $this->stripe->subscriptions[$customer] = [['id' => 'sub_1', 'customer' => $customer, 'status' => 'canceled', 'created' => 1_793_000_000]];

    $url = (new StartCheckout($this->stripe))($user, 'yearly');

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_2')
        ->and($this->stripe->checkouts[1])->toMatchArray(['price' => 'price_yearly', 'trial_days' => null])
        ->and(Subscription::of($user)?->checkout_session_id)->toBe('cs_test_2');
});

it('SUB-007 a second tap while a Checkout is still opening is told to wait, and opens nothing', function () {
    $user = User::factory()->create();
    $opening = Cache::lock(StartCheckout::LOCK.$user->getKey(), 10);
    $opening->get();

    try {
        expect(fn () => (new StartCheckout($this->stripe))($user, 'monthly'))
            ->toThrow(CapabilityFailure::class, 'Your Checkout is still opening. Try again in a moment.');
    } finally {
        $opening->release();
    }
    expect($this->stripe->checkouts)->toBe([]);
});
