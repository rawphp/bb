<?php

namespace App\Domain\Billing;

/**
 * CAP-020 step 4 with billing on: what a user's subscription lets them call.
 *
 * Reads always work, so a lapsed user keeps every screen (SUB-010). A lapsed
 * user's writes are refused except deleting the account and the billing
 * capabilities (SUB-022); signing in and out are host routes, not capabilities.
 * A user who never subscribed can onboard and reach the paywall, but the first
 * plan waits for the trial (SUB-003, SUB-004).
 */
final class BillingRule
{
    /** Writes a lapsed user may still make (SUB-022). */
    public const LAPSED_EXEMPT = ['account_delete'];

    /** Every capability under this prefix is billing, which always stays allowed (SUB-022). */
    public const BILLING_PREFIX = 'billing_';

    /** The write that generates the first plan; it needs a trial or a subscription (SUB-003). */
    public const FIRST_PLAN = 'onboarding_complete';

    public static function allows(Access $access, string $capability, bool $readOnly): bool
    {
        if ($readOnly || str_starts_with($capability, self::BILLING_PREFIX)) {
            return true;
        }

        return match ($access) {
            Access::Trial, Access::Active => true,
            Access::None => $capability !== self::FIRST_PLAN,
            Access::Lapsed => in_array($capability, self::LAPSED_EXEMPT, true),
        };
    }

    /**
     * Whether the user may start a Coach turn (SUB-024). The Coach is part of the
     * plan, so a lapsed user loses it. A new user keeps it to onboard by chat
     * before the paywall (AGT-030); the AGT-005 caps bound every user's use.
     */
    public static function allowsCoach(Access $access): bool
    {
        return $access !== Access::Lapsed;
    }
}
