<?php

namespace App\Domain\Billing;

/**
 * CAP-020 step 4 with billing on: what a user's subscription lets them call.
 *
 * Reads always work, so a lapsed user keeps every screen (SUB-010), except the
 * AI scans, which spend the instance's AI key ({@see AI_READS}). A lapsed
 * user's writes are refused except deleting the account and the billing
 * capabilities (SUB-022), and revoking a token or stopping push; signing in
 * and out are host routes, not capabilities.
 * There is no free tier (SUB-003): a user who has never subscribed may answer
 * onboarding and reach the paywall (SUB-004), and otherwise only what a lapsed
 * user may; the first plan waits for the trial. Existing users of an instance
 * that turns billing on are in that state until they start their trial.
 */
final class BillingRule
{
    /** The free trial a user gets once, started from Checkout (SUB-003). */
    public const TRIAL_DAYS = 7;

    /**
     * Writes a lapsed user may still make (SUB-022): deleting the account, and
     * withdrawing access or consent, which never sits behind a payment.
     */
    public const LAPSED_EXEMPT = ['account_delete', 'token_revoke', 'push_unsubscribe'];

    /** Every capability under this prefix is billing, which always stays allowed (SUB-022). */
    public const BILLING_PREFIX = 'billing_';

    /**
     * Reads that send the user's photo, words or a web page to the instance's AI key (FOOD-042 to FOOD-044,
     * RCP-003, RCP-010). They change nothing, but each one costs the operator, so they come with the plan like
     * a write (SUB-001): a lapsed user or one who has never subscribed is refused them, and keeps every other
     * read (SUB-010).
     */
    public const AI_READS = ['scan_photo', 'scan_describe', 'scan_label', 'recipe_generate', 'recipe_import'];

    /** The write a user who has never subscribed may make besides those, to reach the paywall (SUB-004). */
    public const ONBOARDING = 'onboarding_answer';

    public static function allows(Access $access, string $capability, bool $readOnly): bool
    {
        if (self::isFree($capability, $readOnly)) {
            return true;
        }

        return match ($access) {
            Access::Trial, Access::Active => true,
            Access::None => $capability === self::ONBOARDING || in_array($capability, self::LAPSED_EXEMPT, true),
            Access::Lapsed => in_array($capability, self::LAPSED_EXEMPT, true),
        };
    }

    /** Needs no plan, whatever the subscription: a read other than an AI scan (SUB-010), or billing itself (SUB-022). */
    public static function isFree(string $capability, bool $readOnly): bool
    {
        return ($readOnly && ! in_array($capability, self::AI_READS, true)) || str_starts_with($capability, self::BILLING_PREFIX);
    }

    /**
     * Whether the user may start a Coach turn (SUB-024). The Coach is part of the
     * plan, so a lapsed user loses it. A new user keeps it to onboard by chat
     * before the paywall (AGT-030); its writes are held to onboarding answers by
     * {@see allows()}, and the AGT-005 caps bound every user's use.
     */
    public static function allowsCoach(Access $access): bool
    {
        return $access !== Access::Lapsed;
    }
}
