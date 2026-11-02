<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Billing\StartCheckout;
use App\Billing\StripeClientGateway;
use App\Billing\StripeGateway;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\BillingOn;
use Tests\Support\FakeStripe;

// account_delete with billing on (SUB-021): a subscription Stripe would still renew must be cancelled first, since
// the account and the subscription row that links it to Stripe go together, and nothing could cancel it afterwards.
// Stripe decides which subscriptions renew, not the stored row, which holds only the one the sync picked.
uses()->group('warp-isolated', BillingOn::GROUP);

beforeEach(function () {
    $this->stripe = new FakeStripe;
    $this->app->instance(StripeGateway::class, $this->stripe);
});

const DELETE_CUSTOMER = 'cus_delete_test';

/** A user with a Stripe customer, whose subscriptions Stripe lists as given. */
function customerWith(array $subscriptions): User
{
    $user = User::factory()->create();
    // The stored row is the subscription the sync picked: here the first one listed.
    Subscription::factory()->for($user)->create([
        'stripe_customer_id' => DELETE_CUSTOMER,
        'status' => $subscriptions[0]['status'] ?? null,
        'cancel_at_period_end' => $subscriptions[0]['cancel_at_period_end'] ?? false,
    ]);
    test()->stripe->subscriptions[DELETE_CUSTOMER] = $subscriptions;

    return $user;
}

function deleteWithBilling(User $user): mixed
{
    return test()->actingAs($user)
        ->postJson('/capabilities/account_delete', ['confirmation' => 'DELETE'], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('ACC-005 SUB-007 refuses to delete an account whose subscription would renew, and says to cancel it first', function (string $status) {
    $user = customerWith([['id' => 'sub_a', 'status' => $status, 'cancel_at_period_end' => false]]);

    deleteWithBilling($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Cancel your subscription in Manage subscription first, then delete your account.');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeTrue();
})->with(['trialing', 'active', 'past_due']);

it('ACC-005 SUB-007 a second subscription that would renew stops the deletion, though the stored one is set to end', function () {
    $user = customerWith([
        ['id' => 'sub_b', 'status' => 'active', 'cancel_at_period_end' => true],
        ['id' => 'sub_a', 'status' => 'active', 'cancel_at_period_end' => false],
    ]);

    deleteWithBilling($user)->assertStatus(422);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('ACC-005 deletes an account whose subscriptions all end at the end of their period', function () {
    $user = customerWith([['id' => 'sub_a', 'status' => 'active', 'cancel_at_period_end' => true]]);

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 SUB-022 deletes an account whose subscriptions have ended', function (string $status) {
    $user = customerWith([['id' => 'sub_a', 'status' => $status, 'cancel_at_period_end' => false]]);

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with(['canceled', 'unpaid', 'incomplete_expired']);

it('ACC-005 deletes an account that never subscribed, without asking Stripe', function () {
    $user = User::factory()->create();
    $this->stripe->down = true;

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 SUB-021 keeps the account when Stripe cannot say whether a subscription renews', function () {
    $user = customerWith([['id' => 'sub_a', 'status' => 'canceled', 'cancel_at_period_end' => false]]);
    $this->stripe->down = true;

    // A refusal, not an unsure answer: nothing was deleted, and the user can try again.
    deleteWithBilling($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Payments are unavailable right now. Try again shortly.');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('ACC-005 SUB-007 a first payment still being taken stops the deletion: it renews once it goes through', function () {
    $user = customerWith([['id' => 'sub_a', 'status' => 'incomplete', 'cancel_at_period_end' => false]]);

    deleteWithBilling($user)->assertStatus(422)->assertJsonPath('error.message', DeleteAccount::CANCEL_FIRST);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('ACC-005 an open Checkout page is closed before the account goes, so it can no longer be paid', function () {
    $user = customerWith([]);
    Subscription::query()->where('user_id', $user->id)->update(['checkout_session_id' => 'cs_open']);

    deleteWithBilling($user)->assertOk();

    expect($this->stripe->closed)->toBe(['cs_open'])
        ->and(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 SUB-007 a Checkout page paid between the read and the close stops the deletion', function () {
    $user = customerWith([]);
    Subscription::query()->where('user_id', $user->id)->update(['checkout_session_id' => 'cs_paid']);
    $stripe = new CheckoutStripe($this->stripe);
    // Stripe lists the subscription the page made only from the moment the page is closed, so the list must be read
    // after the close.
    $stripe->onClose = fn () => $this->stripe->subscriptions[DELETE_CUSTOMER] = [['id' => 'sub_new', 'status' => 'trialing', 'cancel_at_period_end' => false]];
    $this->app->instance(StripeGateway::class, $stripe);

    deleteWithBilling($user)->assertStatus(422)->assertJsonPath('error.message', DeleteAccount::CANCEL_FIRST);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('ACC-005 SUB-021 keeps the account when the open Checkout page cannot be closed', function () {
    $user = customerWith([]);
    Subscription::query()->where('user_id', $user->id)->update(['checkout_session_id' => 'cs_open']);
    $stripe = new CheckoutStripe($this->stripe);
    $stripe->onClose = fn () => throw new RuntimeException(StripeClientGateway::UNAVAILABLE);
    $this->app->instance(StripeGateway::class, $stripe);

    deleteWithBilling($user)->assertStatus(422)->assertJsonPath('error.message', StripeClientGateway::UNAVAILABLE);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Subscription::query()->where('user_id', $user->id)->value('checkout_session_id'))->toBe('cs_open');
});

it('ACC-005 a deletion waits while a Checkout is opening, so no page opens between the check and the delete', function () {
    $user = customerWith([]);
    $lock = Cache::lock(StartCheckout::LOCK.$user->getKey(), 60);
    $lock->get();

    try {
        deleteWithBilling($user)->assertStatus(422)->assertJsonPath('error.message', DeleteAccount::CHECKOUT_OPENING);
    } finally {
        $lock->release();
    }

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

/** FakeStripe, with a hook that runs when a Checkout page is closed. */
final class CheckoutStripe implements StripeGateway
{
    /** @var (Closure(): mixed)|null */
    public ?Closure $onClose = null;

    public function __construct(private readonly FakeStripe $fake) {}

    public function createCustomer(string $email, string $name, int $userId): string
    {
        return $this->fake->createCustomer($email, $name, $userId);
    }

    public function checkout(string $customer, string $price, ?int $trialDays, string $returnUrl): array
    {
        return $this->fake->checkout($customer, $price, $trialDays, $returnUrl);
    }

    public function closeCheckout(string $session): ?string
    {
        if ($this->onClose !== null) {
            ($this->onClose)();
        }

        return $this->fake->closeCheckout($session);
    }

    public function portalUrl(string $customer, string $returnUrl): string
    {
        return $this->fake->portalUrl($customer, $returnUrl);
    }

    public function subscriptions(string $customer): array
    {
        return $this->fake->subscriptions($customer);
    }

    public function price(string $price): array
    {
        return $this->fake->price($price);
    }
}
