<?php

use App\Billing\StripeGateway;
use App\Models\Subscription;
use App\Models\User;
use Tests\Support\BillingOn;
use Tests\Support\FakeStripe;

uses()->group('warp-isolated', BillingOn::GROUP);

beforeEach(function () {
    $this->stripe = new FakeStripe;
    $this->app->instance(StripeGateway::class, $this->stripe);
    $this->row = Subscription::factory()->for(User::factory())->create(['stripe_customer_id' => 'cus_1']);
});

/** A Stripe subscription object, as an event carries it and as Stripe lists it. */
function stripeSubscription(array $overrides = []): array
{
    return array_replace([
        'id' => 'sub_1',
        'object' => 'subscription',
        'customer' => 'cus_1',
        'status' => 'active',
        'created' => 1_793_000_000,
        'cancel_at_period_end' => false,
        'trial_end' => null,
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1',
            'object' => 'subscription_item',
            'current_period_end' => 1_804_067_200,
            'price' => ['id' => 'price_yearly', 'object' => 'price'],
        ]]],
    ], $overrides);
}

/** Posts a Stripe event to the webhook, signed with the given secret the way Stripe signs it. */
function postStripeEvent(string $type, array $object, string $secret = 'whsec_bb_test')
{
    $payload = json_encode(['id' => 'evt_'.bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'created' => time(), 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return test()->call('POST', '/stripe/webhook', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $payload);
}

it('SUB-021 on a verified subscription event, keeps what Stripe says about the customer now', function (string $type) {
    $this->stripe->subscriptions['cus_1'] = [stripeSubscription(['status' => 'trialing'])];

    postStripeEvent($type, stripeSubscription(['status' => 'trialing']))->assertOk();

    expect($this->row->refresh()->status)->toBe('trialing')
        ->and($this->row->plan)->toBe('yearly');
})->with(['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted']);

it('SUB-021 an event that arrives late cannot undo a newer state: the state comes from Stripe, not the event', function () {
    $this->stripe->subscriptions['cus_1'] = [stripeSubscription(['status' => 'canceled'])];

    postStripeEvent('customer.subscription.created', stripeSubscription(['status' => 'active']))->assertOk();

    expect($this->row->refresh()->status)->toBe('canceled');
});

it('SUB-021 refuses a webhook it cannot verify, and changes nothing', function (string $signature) {
    $this->stripe->subscriptions['cus_1'] = [stripeSubscription(['status' => 'canceled'])];
    $payload = json_encode(['type' => 'customer.subscription.deleted', 'created' => time(), 'data' => ['object' => stripeSubscription(['status' => 'canceled'])]]);

    $this->call('POST', '/stripe/webhook', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload)
        ->assertStatus(400);

    expect($this->row->refresh()->status)->toBeNull();
})->with(['missing' => '', 'forged' => 't=1,v1=deadbeef']);

it('SUB-021 refuses a webhook signed with another secret', function () {
    $this->stripe->subscriptions['cus_1'] = [stripeSubscription(['status' => 'canceled'])];

    postStripeEvent('customer.subscription.deleted', stripeSubscription(['status' => 'canceled']), secret: 'whsec_someone_else')
        ->assertStatus(400);

    expect($this->row->refresh()->status)->toBeNull();
});

it('SUB-021 answers Stripe and changes nothing for an event that carries no subscription state', function () {
    $this->stripe->subscriptions['cus_1'] = [stripeSubscription()];

    postStripeEvent('invoice.paid', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1'])->assertOk();

    expect($this->row->refresh()->status)->toBeNull();
});

it('SUB-021 takes no session or CSRF token on the webhook', function () {
    $this->stripe->subscriptions['cus_1'] = [stripeSubscription()];

    postStripeEvent('customer.subscription.created', stripeSubscription())
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});
