<?php

use App\Capabilities\HostAuthorizer;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\Capabilities\Support\SystemActor;

it('CAP-020 allows an invoke by a signed-in user', function () {
    $context = new CapabilityContext(caller: 'http', actor: new User);

    expect((new HostAuthorizer)->authorize('account_show', [], $context))->toBeTrue();
});

it('CAP-020 refuses an invoke with no signed-in user', function (mixed $context) {
    expect((new HostAuthorizer)->authorize('account_show', [], $context))->toBeFalse();
})->with([
    'system actor' => fn () => new CapabilityContext(caller: 'job', actor: SystemActor::named('scheduler')),
    'actor that is not a user' => fn () => new CapabilityContext(caller: 'http', actor: new stdClass),
    'no context' => [null],
]);

it('CAP-020 the bus refuses account_show on every surface when the actor is not a signed-in user', function (string $caller) {
    $options = ['caller' => $caller, 'actor' => new class
    {
        public int $id = 7;
    }];
    if ($caller === 'job') {
        $options['job'] = ['tenant_id' => 't-1'];
    }

    $result = app(CapabilityRegistry::class)->invoke('account_show', [], $options);

    expect($result->isOk())->toBeFalse()
        ->and($result->toArray()['error']['code'])->toBe('forbidden')
        ->and($result->toArray()['meta']['stages'])->toContain('authorize');
})->with(['http', 'job', 'mcp', 'cli']);
