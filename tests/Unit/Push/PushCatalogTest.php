<?php

use App\Capabilities\Support\HostDefinition;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

it('NOTIF-010 registers the push capabilities on http, job, mcp and cli, and the writes affect push_show', function () {
    $registry = app(CapabilityRegistry::class);

    expect($registry->get('push_show')->readOnly)->toBeTrue();

    foreach (['push_show', 'push_subscribe', 'push_unsubscribe'] as $name) {
        expect($registry->get($name)->surfaces)->toEqualCanonicalizing(['http', 'job', 'mcp', 'cli']);
    }

    foreach (['push_subscribe', 'push_unsubscribe'] as $name) {
        expect(HostDefinition::affects($registry->get($name)))->toBe(['push_show']);
    }
});
