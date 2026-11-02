<?php

use App\Capabilities\HostAuthorizer;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
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

it('CAP-023 allows a personal access token only the capabilities its abilities name', function () {
    $user = (new User)->withAccessToken(new PersonalAccessToken(['abilities' => ['steps_log']]));
    $everything = (new User)->withAccessToken(new PersonalAccessToken(['abilities' => ['*']]));
    $session = (new User)->withAccessToken(new TransientToken);
    $authorize = fn (User $actor, string $capability): bool => (new HostAuthorizer)->authorize($capability, [], new CapabilityContext(caller: 'http', actor: $actor));

    expect($authorize($user, 'steps_log'))->toBeTrue()
        ->and($authorize($user, 'account_delete'))->toBeFalse()
        ->and($authorize($everything, 'account_delete'))->toBeTrue()
        ->and($authorize($session, 'account_delete'))->toBeTrue();
});

it('CAP-031 refuses an excluded capability to the agent, whatever reached the bus', function (string $capability) {
    $authorizer = new HostAuthorizer;

    expect($authorizer->authorize($capability, [], new CapabilityContext(caller: 'agent', actor: new User)))->toBeFalse()
        ->and($authorizer->authorize($capability, [], new CapabilityContext(caller: 'http', actor: new User)))->toBeTrue()
        ->and($authorizer->authorize('account_show', [], new CapabilityContext(caller: 'agent', actor: new User)))->toBeTrue();
})->with(['account_delete', 'token_create', 'token_revoke', 'billing_checkout']);

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
