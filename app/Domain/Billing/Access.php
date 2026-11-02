<?php

namespace App\Domain\Billing;

/**
 * What a user's subscription gives them, from the status Stripe last sent in a
 * verified webhook (SUB-021). The server never learns it from the browser.
 */
enum Access: string
{
    /** Never subscribed: onboarding and the paywall (SUB-004). */
    case None = 'none';

    /** The 7-day free trial Checkout started (SUB-003). */
    case Trial = 'trial';

    /** Paid, or a failed payment Stripe is still retrying. */
    case Active = 'active';

    /** Ended: every screen shows, read-only (SUB-010). */
    case Lapsed = 'lapsed';

    /** How strongly this access decides an account with several subscriptions: paid, then trial, then a first payment, then ended. */
    public function rank(): int
    {
        return match ($this) {
            self::Active => 3,
            self::Trial => 2,
            self::None => 1,
            self::Lapsed => 0,
        };
    }

    public static function fromStripeStatus(?string $status): self
    {
        return match ($status) {
            // A first payment still being taken has not started anything yet.
            null, 'incomplete' => self::None,
            'trialing' => self::Trial,
            'active', 'past_due' => self::Active,
            // canceled, unpaid, incomplete_expired, paused, and any status Stripe adds later:
            // an unknown status never unlocks the app.
            default => self::Lapsed,
        };
    }
}
