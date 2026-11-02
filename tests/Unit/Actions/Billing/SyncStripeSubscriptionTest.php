<?php

use App\Actions\Billing\SyncStripeSubscription;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripe;

// The action's job is the row it keeps in subscriptions, so it runs on the database.
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.prices' => ['monthly' => 'price_monthly', 'six_months' => null, 'yearly' => 'price_yearly']]);
    $this->stripe = new FakeStripe;
    $this->row = Subscription::factory()->create(['stripe_customer_id' => 'cus_1']);
});

/** A subscription as Stripe lists it, on the current API: the period is on the item. */
function listedSubscription(array $overrides = [], string $price = 'price_yearly'): array
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
            'current_period_end' => strtotime('2027-03-03 10:00:00 UTC'),
            'price' => ['id' => $price],
        ]]],
    ], $overrides);
}

function sync(FakeStripe $stripe, string $customer = 'cus_1'): void
{
    (new SyncStripeSubscription($stripe))($customer);
}

it('SUB-021 keeps what Stripe says now about the customer\'s subscription', function () {
    $this->stripe->subscriptions['cus_1'] = [listedSubscription(['status' => 'trialing', 'trial_end' => 1_793_700_000, 'cancel_at_period_end' => true])];

    sync($this->stripe);

    $row = $this->row->refresh();
    expect($row->stripe_subscription_id)->toBe('sub_1')
        ->and($row->status)->toBe('trialing')
        ->and($row->plan)->toBe('yearly')
        ->and($row->current_period_end?->toIso8601String())->toBe('2027-03-03T10:00:00+00:00')
        ->and($row->trial_ends_at?->getTimestamp())->toBe(1_793_700_000)
        ->and($row->cancel_at_period_end)->toBeTrue();
});

it('SUB-010 a paying customer with an old, ended subscription keeps access, whichever event came last', function () {
    $this->stripe->subscriptions['cus_1'] = [
        listedSubscription(['id' => 'sub_old', 'status' => 'canceled', 'created' => 1_700_000_000]),
        listedSubscription(['id' => 'sub_new', 'status' => 'active']),
    ];

    sync($this->stripe);

    expect($this->row->refresh()->stripe_subscription_id)->toBe('sub_new')
        ->and($this->row->status)->toBe('active');
});

it('SUB-021 reads the period from the subscription on Stripe API versions before 2025-03-31', function () {
    $old = listedSubscription(['current_period_end' => strtotime('2027-03-03 10:00:00 UTC')]);
    unset($old['items']['data'][0]['current_period_end']);
    $this->stripe->subscriptions['cus_1'] = [$old];

    sync($this->stripe);

    expect($this->row->refresh()->current_period_end?->toIso8601String())->toBe('2027-03-03T10:00:00+00:00');
});

it('SUB-002 a price the instance does not sell leaves the plan unnamed', function () {
    $this->stripe->subscriptions['cus_1'] = [listedSubscription(price: 'price_retired')];

    sync($this->stripe);

    expect($this->row->refresh()->plan)->toBeNull()
        ->and($this->row->status)->toBe('active');
});

it('SUB-021 changes nothing for a customer this instance did not make, or one with no subscription', function () {
    $this->stripe->subscriptions['cus_other'] = [listedSubscription(['customer' => 'cus_other'])];

    sync($this->stripe, 'cus_other');
    sync($this->stripe);

    expect($this->row->refresh()->status)->toBeNull()
        ->and(Subscription::query()->count())->toBe(1);
});
