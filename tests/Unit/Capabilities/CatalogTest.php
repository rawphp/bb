<?php

use Rawphp\Capabilities\Registry\CapabilityRegistry;

it('CAP-003 names every capability <domain>_<verb> and maps it to a CLI domain and verb', function () {
    foreach (app(CapabilityRegistry::class)->all() as $name => $capability) {
        expect($name)->toMatch('/^[a-z][a-z0-9_]{0,63}$/')
            ->and($capability->cliDomain)->not->toBeNull("{$name} has no CLI domain")
            ->and($capability->cliVerb)->not->toBeNull("{$name} has no CLI verb");
    }
});

it('CAP-005 describes every capability in two or three sentences', function () {
    foreach (app(CapabilityRegistry::class)->all() as $name => $capability) {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($capability->description), flags: PREG_SPLIT_NO_EMPTY);

        expect(count($sentences))->toBeGreaterThanOrEqual(2, "{$name} needs a longer description")
            ->toBeLessThanOrEqual(3, "{$name} needs a shorter description");
    }
});

it('CAP-004 registers account_show on http, job, mcp and cli', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->get('account_show')->surfaces)->toEqualCanonicalizing(['http', 'job', 'mcp', 'cli'])
        ->and(array_keys(array_filter($registry->globallyEnabledSurfaces())))->toEqualCanonicalizing(['http', 'job', 'mcp', 'cli']);
});
