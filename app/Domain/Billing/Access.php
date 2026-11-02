<?php

namespace App\Domain\Billing;

/**
 * What a user's subscription gives them, from its Stripe status, which the
 * server reads back from Stripe's API (SUB-021), never from the browser.
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

    /**
     * How strongly this access decides an account with several subscriptions:
     * paid, then trial, then ended. A first payment not yet taken ranks last,
     * so it never lifts a lapsed account out of read-only before it is paid.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Active => 3,
            self::Trial => 2,
            self::Lapsed => 1,
            self::None => 0,
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
