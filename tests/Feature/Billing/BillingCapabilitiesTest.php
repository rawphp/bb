<?php

use App\Billing\PlanPrices;
use App\Billing\StripeGateway;
use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\StripeBilling;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\SchemaSnapshot;
use Tests\Support\BillingOn;
use Tests\Support\FakeStripe;
use Tests\Support\ScreenFixture;

uses()->group('warp-isolated', BillingOn::GROUP);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    $this->stripe = new FakeStripe;
    $this->app->instance(StripeGateway::class, $this->stripe);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('CAP-032 keeps each billing capability\'s schema snapshot equal to its live schema', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (['billing_show', 'billing_checkout_start', 'billing_portal_open'] as $name) {
        foreach (['input', 'output'] as $side) {
            $definition = $registry->get($name);
            $live = $side === 'input' ? $definition->inputSchema() : $definition->outputSchema();
            $snapshot = json_decode((string) file_get_contents(base_path("tests/fixtures/schemas/{$name}.{$side}.json")), true, flags: JSON_THROW_ON_ERROR);

            expect(SchemaSnapshot::schemasEqual($snapshot, $live))->toBeTrue("{$name}.{$side}.json differs from the live schema");
        }
    }
});

it('SUB-007 billing_checkout_start sends a subscribed user to the portal instead of a second subscription', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'active']);

    $this->actingAs($user)->postJson('/capabilities/billing_checkout_start', ['plan' => 'yearly'])
        ->assertStatus(409)
        ->assertJsonPath('error.message', 'You already have a subscription. Switch plans in Manage subscription.');
});

it('SUB-020 registers the billing capabilities, the Stripe gate and the webhook with billing on', function () {
    $names = array_keys(app(CapabilityRegistry::class)->all());

    expect($names)->toContain('billing_show', 'billing_checkout_start', 'billing_portal_open')
        ->and(app(BillingGate::class))->toBeInstanceOf(StripeBilling::class)
        ->and(Route::has('billing.webhook'))->toBeTrue();
});

it('SUB-004 billing_show offers the plans at Stripe\'s prices and the 7-day trial to a new user', function () {
    $data = $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/billing_show')
        ->assertOk()
        ->json('data');

    assertMatchesScreenFixture('billing_show', $data);
    expect($data)->toBe(ScreenFixture::load('billing_show.empty'));
});

it('SUB-007 billing_show shows the plan, when it renews, and that it can be managed', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create([
        'status' => 'active',
        'plan' => 'yearly',
        'current_period_end' => '2027-03-03 10:00:00',
    ]);

    $data = $this->actingAs($user)->postJson('/capabilities/billing_show')->assertOk()->json('data');

    assertMatchesScreenFixture('billing_show', $data);
    expect($data)->toBe(ScreenFixture::load('billing_show'));
});

it('SUB-010 billing_show shows the lapsed banner once the subscription has ended', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'canceled', 'plan' => 'yearly']);

    $data = $this->actingAs($user)->postJson('/capabilities/billing_show')->assertOk()->json('data');

    assertMatchesScreenFixture('billing_show', $data);
    expect($data)->toBe(ScreenFixture::load('billing_show.warning'));
});

it('SUB-003 billing_show says when the free trial ends', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'trialing', 'plan' => 'monthly', 'trial_ends_at' => '2026-11-10 09:00:00']);

    $this->actingAs($user)->postJson('/capabilities/billing_show')
        ->assertOk()
        ->assertJsonPath('data.status', ['text' => 'Free trial, ends 10 November 2026', 'tone' => 'info'])
        ->assertJsonPath('data.primary_label', 'Subscribe')
        ->assertJsonPath('data.can_manage', true);
});

it('SUB-010 with Stripe down and no price kept, billing_show still shows a lapsed user the status, the banner and the way to resubscribe', function () {
    $this->stripe->down = true;
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'canceled', 'plan' => 'yearly']);

    $data = $this->actingAs($user)->postJson('/capabilities/billing_show')->assertOk()->json('data');

    expect($data['status'])->toBe(['text' => 'Ended', 'tone' => 'warning'])
        ->and($data['lapsed'])->toBe(['text' => 'Your subscription has ended. Your data is read-only.', 'action_label' => 'Resubscribe'])
        ->and($data['plans'])->toBe([])
        ->and($data['default_plan'])->toBe('yearly')
        ->and($data['can_manage'])->toBeTrue();
});

it('SUB-002 with Stripe down, billing_show shows the prices kept from the last refresh', function () {
    app(PlanPrices::class)->refresh('price_monthly');
    $this->stripe->down = true;

    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/billing_show')->assertOk()->json('data');

    expect($data['plans'])->toBe([['plan' => 'monthly', 'title' => 'Monthly', 'price_text' => '$34.99 per month', 'detail' => null]]);
});

it('SUB-002 billing_show reads Stripe for prices only when none is kept, and keeps them', function () {
    $this->actingAs(User::factory()->create())->postJson('/capabilities/billing_show')->assertOk();
    $this->stripe->down = true;

    $this->actingAs(User::factory()->create())->postJson('/capabilities/billing_show')
        ->assertOk()
        ->assertJsonCount(3, 'data.plans');
});

it('SUB-002 the scheduled refresh keeps every plan\'s price from Stripe, and keeps the old one when Stripe fails', function () {
    $this->artisan('billing:refresh-prices')->assertSuccessful();
    $this->stripe->prices['price_monthly']['amount'] = 3999;
    $this->stripe->down = true;

    $this->artisan('billing:refresh-prices')->assertFailed();

    expect(app(PlanPrices::class)->get('price_monthly'))->toMatchArray(['amount' => 3499]);
});

it('SUB-007 billing_show says a cancelled subscription ends rather than renews', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'active', 'plan' => 'monthly', 'current_period_end' => '2026-12-01 09:00:00', 'cancel_at_period_end' => true]);

    $this->actingAs($user)->postJson('/capabilities/billing_show')
        ->assertJsonPath('data.status', ['text' => 'Monthly, ends 1 December 2026', 'tone' => 'warning']);
});

it('SUB-007 billing_show says when a payment failed', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'past_due', 'plan' => 'monthly']);

    $this->actingAs($user)->postJson('/capabilities/billing_show')
        ->assertJsonPath('data.status', ['text' => 'Payment failed. Update your card in Manage subscription.', 'tone' => 'warning']);
});

it('SUB-003 billing_checkout_start opens Checkout with the 7-day trial for a user who never subscribed', function () {
    $user = User::factory()->create(['name' => 'Sam Lifter', 'email' => 'sam@example.com']);

    $this->actingAs($user)->postJson('/capabilities/billing_checkout_start', ['plan' => 'six_months'])
        ->assertOk()
        ->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_test_1')
        ->assertJsonPath('data.invalidates', ['billing_show{}']);

    expect($this->stripe->customers)->toBe([['email' => 'sam@example.com', 'name' => 'Sam Lifter', 'user_id' => $user->id]])
        ->and($this->stripe->checkouts)->toBe([['customer' => 'cus_test_1', 'price' => 'price_six_months', 'trial_days' => 7, 'return_url' => config('app.url')]])
        ->and(Subscription::query()->where('user_id', $user->id)->value('stripe_customer_id'))->toBe('cus_test_1');
});

it('SUB-005 billing_checkout_start keeps one Stripe customer per account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/billing_checkout_start', ['plan' => 'monthly'])->assertOk();
    $this->actingAs($user)->postJson('/capabilities/billing_checkout_start', ['plan' => 'yearly'])->assertOk();

    expect($this->stripe->customers)->toHaveCount(1)
        ->and(array_column($this->stripe->checkouts, 'customer'))->toBe(['cus_test_1', 'cus_test_1']);
});

it('SUB-003 gives the free trial once: a user who has subscribed before pays from the start', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['stripe_customer_id' => 'cus_old', 'status' => 'canceled']);

    $this->actingAs($user)->postJson('/capabilities/billing_checkout_start', ['plan' => 'monthly'])->assertOk();

    expect($this->stripe->customers)->toBe([])
        ->and($this->stripe->checkouts[0])->toMatchArray(['customer' => 'cus_old', 'trial_days' => null]);
});

it('CAP-004 billing_checkout_start answers 401 signed out and 422 for a plan it does not sell', function () {
    $this->postJson('/capabilities/billing_checkout_start', ['plan' => 'monthly'])->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/billing_checkout_start', ['plan' => 'weekly'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect($this->stripe->checkouts)->toBe([]);
});

it('SUB-007 billing_portal_open opens the Stripe customer portal for the user\'s own customer', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['stripe_customer_id' => 'cus_mine', 'status' => 'active']);
    Subscription::factory()->create(['stripe_customer_id' => 'cus_theirs', 'status' => 'active']);

    $this->actingAs($user)->postJson('/capabilities/billing_portal_open')
        ->assertOk()
        ->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/test_1');

    expect($this->stripe->portals)->toBe([['customer' => 'cus_mine', 'return_url' => config('app.url')]]);
});

it('SUB-007 billing_portal_open refuses a user with no subscription to manage', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/billing_portal_open')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    expect($this->stripe->portals)->toBe([]);
});
