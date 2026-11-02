<?php

use App\Models\Subscription;
use App\Models\User;
use Tests\Support\BillingOn;

uses()->group('warp-isolated', BillingOn::GROUP);

const EARLIER = 1_793_000_000; // 2026-10-26
const LATER = 1_793_100_000;

/** A Stripe subscription object as a webhook carries it. */
function stripeSubscription(array $overrides = []): array
{
    return array_replace([
        'id' => 'sub_1',
        'object' => 'subscription',
        'customer' => 'cus_1',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'trial_end' => null,
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1',
            'object' => 'subscription_item',
            'current_period_end' => strtotime('2027-03-03 10:00:00 UTC'),
            'price' => ['id' => 'price_yearly', 'object' => 'price'],
        ]]],
    ], $overrides);
}

/** Posts a Stripe event to the webhook, signed with the given secret the way Stripe signs it. */
function postStripeEvent(string $type, array $object, int $created, string $secret = 'whsec_bb_test')
{
    $payload = json_encode(['id' => 'evt_'.bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return test()->call('POST', '/stripe/webhook', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $payload);
}

function customerRow(string $customer = 'cus_1'): Subscription
{
    return Subscription::factory()->for(User::factory())->create(['stripe_customer_id' => $customer]);
}

it('SUB-021 learns a new subscription from a verified webhook', function () {
    $row = customerRow();

    postStripeEvent('customer.subscription.created', stripeSubscription(['status' => 'trialing', 'trial_end' => strtotime('2026-11-10 09:00:00 UTC')]), created: EARLIER)
        ->assertOk();

    $row->refresh();
    expect($row->stripe_subscription_id)->toBe('sub_1')
        ->and($row->status)->toBe('trialing')
        ->and($row->plan)->toBe('yearly')
        ->and($row->current_period_end?->toIso8601String())->toBe('2027-03-03T10:00:00+00:00')
        ->and($row->trial_ends_at?->toIso8601String())->toBe('2026-11-10T09:00:00+00:00')
        ->and($row->cancel_at_period_end)->toBeFalse();
});

it('SUB-021 refuses a webhook it cannot verify, and changes nothing', function (string $signature) {
    $row = customerRow();
    $payload = json_encode(['type' => 'customer.subscription.deleted', 'created' => EARLIER, 'data' => ['object' => stripeSubscription(['status' => 'canceled'])]]);

    $this->call('POST', '/stripe/webhook', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload)
        ->assertStatus(400);

    expect($row->refresh()->status)->toBeNull();
})->with(['missing' => '', 'forged' => 't=1,v1=deadbeef']);

it('SUB-021 refuses a webhook signed with another secret', function () {
    $row = customerRow();

    postStripeEvent('customer.subscription.deleted', stripeSubscription(['status' => 'canceled']), created: EARLIER, secret: 'whsec_someone_else')
        ->assertStatus(400);

    expect($row->refresh()->status)->toBeNull();
});

it('SUB-010 a deleted subscription lapses the user', function () {
    $row = customerRow();
    postStripeEvent('customer.subscription.created', stripeSubscription(), created: EARLIER)->assertOk();

    postStripeEvent('customer.subscription.deleted', stripeSubscription(['status' => 'canceled']), created: LATER)->assertOk();

    expect($row->refresh()->status)->toBe('canceled');
});

it('SUB-007 a plan switched or cancelled in the portal reaches the account', function () {
    $row = customerRow();
    postStripeEvent('customer.subscription.created', stripeSubscription(), created: EARLIER)->assertOk();

    $monthly = stripeSubscription(['cancel_at_period_end' => true]);
    $monthly['items']['data'][0]['price']['id'] = 'price_monthly';
    postStripeEvent('customer.subscription.updated', $monthly, created: LATER)->assertOk();

    expect($row->refresh()->plan)->toBe('monthly')
        ->and($row->cancel_at_period_end)->toBeTrue();
});

it('SUB-021 ignores an event older than the one it already applied', function () {
    $row = customerRow();
    postStripeEvent('customer.subscription.updated', stripeSubscription(['status' => 'canceled']), created: LATER)->assertOk();

    postStripeEvent('customer.subscription.created', stripeSubscription(['status' => 'active']), created: EARLIER)->assertOk();

    expect($row->refresh()->status)->toBe('canceled');
});

it('SUB-021 answers Stripe and changes nothing for a customer or an event it does not know', function () {
    $row = customerRow();

    postStripeEvent('customer.subscription.updated', stripeSubscription(['customer' => 'cus_unknown']), created: EARLIER)->assertOk();
    postStripeEvent('invoice.paid', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1'], created: EARLIER)->assertOk();

    expect($row->refresh()->status)->toBeNull()
        ->and(Subscription::query()->count())->toBe(1);
});

it('SUB-021 takes no session or CSRF token on the webhook', function () {
    customerRow();

    postStripeEvent('customer.subscription.created', stripeSubscription(), created: EARLIER)
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});
