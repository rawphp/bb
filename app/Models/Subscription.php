<?php

namespace App\Models;

use App\Domain\Billing\Access;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An account's Stripe customer and subscription (SUB-021). Checkout creates the
 * row with the customer; only verified webhooks change the subscription.
 *
 * @property int $user_id
 * @property string $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string|null $status
 * @property string|null $plan
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $stripe_event_at
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
        'stripe_event_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'trial_ends_at' => 'datetime',
            'stripe_event_at' => 'datetime',
        ];
    }

    public static function of(User $user): ?self
    {
        return self::query()->where('user_id', $user->getKey())->first();
    }

    public static function accessOf(User $user): Access
    {
        return Access::fromStripeStatus(self::of($user)?->status);
    }

    /** Whether Stripe has billed this account before: the free trial is given once (SUB-003). */
    public function hasSubscribed(): bool
    {
        return $this->stripe_subscription_id !== null;
    }
}
