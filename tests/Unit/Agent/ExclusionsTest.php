<?php

use App\Agent\Exclusions;

it('CAP-031 keeps account deletion and the agent\'s own credentials away from the agent, each with a reason', function () {
    foreach (['account_delete', 'token_create', 'token_revoke'] as $name) {
        expect(Exclusions::reason($name))->toBeString()->not->toBe('');
    }
});

it('CAP-031 keeps every billing capability away from the agent', function () {
    expect(Exclusions::reason('billing_checkout_start'))->toBe('Money needs a person.');
});

it('CAP-031 lets the agent call everything else', function () {
    expect(Exclusions::reason('account_show'))->toBeNull()
        ->and(Exclusions::reason('token_list'))->toBeNull()
        ->and(Exclusions::reason('meal_billing_note'))->toBeNull();
});
