<?php

namespace Tests\Support;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;

/**
 * Boots a test's application with billing on, as the hosted instance runs.
 * Billing decides at boot what registers (SUB-020), so a test cannot turn it
 * on afterwards. A test file opts in with
 * `uses()->group('warp-isolated', BillingOn::GROUP)`: TestCase boots each of its
 * tests in its own application through {@see boot()}.
 */
final class BillingOn
{
    public const GROUP = 'billing-on';

    /** The environment a hosted instance sets (docs/self-hosting.md, "Billing"). */
    public const ENV = [
        'BILLING_ENABLED' => 'true',
        'STRIPE_SECRET' => 'sk_test_bb',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_bb_test',
        'STRIPE_PRICE_MONTHLY' => 'price_monthly',
        'STRIPE_PRICE_SIX_MONTHS' => 'price_six_months',
        'STRIPE_PRICE_YEARLY' => 'price_yearly',
    ];

    /** @param  Closure(): Application  $boot */
    public static function boot(Closure $boot): Application
    {
        foreach (self::ENV as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        // Laravel's env repository is static. One an earlier boot made counts .env's
        // BILLING_ENABLED=false as its own and would write it over the value above,
        // so start a fresh one, which leaves values already set alone.
        Env::enablePutenv();

        try {
            return $boot();
        } finally {
            foreach (array_keys(self::ENV) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }
}
