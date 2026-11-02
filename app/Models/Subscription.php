<?php

namespace App\Models;

use App\Actions\Billing\SyncStripeSubscription;
use App\Domain\Billing\Access;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An account's Stripe customer and subscription (SUB-021). Checkout creates the
 * row with the customer; the subscription is written only by
 * {@see SyncStripeSubscription}, from what Stripe's API says.
 *
 * @property int $user_id
 * @property string $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string|null $status
 * @property string|null $plan
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $trial_ends_at
 * @property string|null $checkout_session_id
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'status',
        'plan',
        'current_period_end',
        'cancel_at_period_end',
        'trial_ends_at',
        'checkout_session_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    public static function of(User $user): ?self
    {
        return $user->subscription()->first();
    }

    public static function accessOf(User $user): Access
    {
        return Access::fromStripeStatus(self::of($user)?->status);
    }

    /**
     * Whether Stripe will charge the account again: a trial or a paid subscription not set to end at its period's
     * end. Deleting the account would leave it charging with nothing left to cancel it from (ACC-005, SUB-007).
     */
    public function renews(): bool
    {
        return in_array(Access::fromStripeStatus($this->status), [Access::Trial, Access::Active], true) && ! $this->cancel_at_period_end;
    }

    /** Whether Stripe has billed this account before: the free trial is given once (SUB-003). */
    public function hasSubscribed(): bool
    {
        return $this->stripe_subscription_id !== null;
    }
}
