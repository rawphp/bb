<?php

namespace App\Capabilities\Support;

/**
 * The value lists that more than one Settings contract carries and no domain
 * enum backs yet, for a contract's `#[Field(enum: ...)]`. Each is written once
 * here, so a read never offers a value its write refuses, or the reverse. When
 * an API issue adds the enum, its list moves to DomainValues.
 */
final class SettingsValues
{
    /** SUB-002: the plans billing_show offers and billing_checkout_start buys. */
    public const BILLING_PLANS = ['monthly', 'six_months', 'yearly'];

    /** NOTIF-007: the reminder types settings_show lists and notification_prefs_set switches. */
    public const NOTIFICATION_KINDS = ['weigh_in', 'meal', 'workout', 'weekly_review', 'next_mesocycle', 'retarget'];
}
