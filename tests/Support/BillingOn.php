<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;

/**
 * Boots each test's own application with billing on, as the hosted instance
 * runs. Billing decides at boot what registers (SUB-020), so a test cannot turn
 * it on afterwards. Add with `uses(BillingOn::class)`.
 */
trait BillingOn
{
    /** The environment a hosted instance sets (docs/self-hosting.md, "Billing"). */
    public const BILLING_ENV = [
        'BILLING_ENABLED' => 'true',
        'STRIPE_SECRET' => 'sk_test_bb',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_bb_test',
        'STRIPE_PRICE_MONTHLY' => 'price_monthly',
        'STRIPE_PRICE_SIX_MONTHS' => 'price_six_months',
        'STRIPE_PRICE_YEARLY' => 'price_yearly',
    ];

    public function createApplication(): Application
    {
        foreach (self::BILLING_ENV as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return parent::createApplication();
        } finally {
            foreach (array_keys(self::BILLING_ENV) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    /** The shared warm application boots with billing off, so this test boots its own. */
    protected function warpShouldIsolate(): bool
    {
        return true;
    }
}
