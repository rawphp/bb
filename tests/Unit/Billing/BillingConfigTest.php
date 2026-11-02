<?php

/** Loads config/billing.php with the given environment, as at boot. */
function billingConfig(array $env): array
{
    $keys = ['BILLING_ENABLED', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_PRICE_MONTHLY', 'STRIPE_PRICE_SIX_MONTHS', 'STRIPE_PRICE_YEARLY'];
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        unset($_ENV[$key], $_SERVER[$key]);
        if (array_key_exists($key, $env)) {
            $_ENV[$key] = $env[$key];
        }
    }

    try {
        return require base_path('config/billing.php');
    } finally {
        foreach ($saved as $key => [$envValue, $serverValue]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($envValue !== null) {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue !== null) {
                $_SERVER[$key] = $serverValue;
            }
        }
    }
}

const HOSTED = [
    'BILLING_ENABLED' => 'true',
    'STRIPE_SECRET' => 'sk_test_bb',
    'STRIPE_WEBHOOK_SECRET' => 'whsec_bb',
    'STRIPE_PRICE_YEARLY' => 'price_yearly',
];

it('SUB-020 billing is off unless BILLING_ENABLED is true', function () {
    expect(billingConfig([])['enabled'])->toBeFalse()
        ->and(billingConfig(['BILLING_ENABLED' => 'false'])['enabled'])->toBeFalse()
        ->and(billingConfig(HOSTED)['enabled'])->toBeTrue();
});

it('SUB-020 an unreadable BILLING_ENABLED stops the app from booting rather than guessing', function () {
    billingConfig(['BILLING_ENABLED' => 'yes please']);
})->throws(InvalidArgumentException::class, 'BILLING_ENABLED');

it('SUB-021 billing on needs the Stripe keys and at least one price', function (string $missing) {
    $env = HOSTED;
    unset($env[$missing]);

    billingConfig($env);
})->with(['STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_PRICE_YEARLY'])->throws(InvalidArgumentException::class);

it('SUB-002 sells only the plans that have a Stripe price', function () {
    expect(billingConfig(HOSTED)['prices'])->toBe(['monthly' => null, 'six_months' => null, 'yearly' => 'price_yearly']);
});
