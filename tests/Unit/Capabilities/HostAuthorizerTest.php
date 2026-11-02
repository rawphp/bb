<?php

use App\Agent\Exclusions;
use App\Capabilities\HostAuthorizer;
use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\NoAgentPolicy;
use App\Capabilities\Support\NoBilling;
use App\Capabilities\Support\Ownership;
use App\Capabilities\Support\OwnsInput;
use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\TransientToken;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\Capabilities\Support\SystemActor;

/** A user with id 7, carrying a token with these abilities (null: no token, as a session or the agent). */
function authorizerUser(?array $abilities = null): User
{
    $user = tap(new User, fn (User $u) => $u->id = 7);
    if ($abilities !== null) {
        $user->withAccessToken(new PersonalAccessToken(['abilities' => $abilities, 'scope' => TokenScope::Custom]));
    }

    return $user;
}

/** A fresh authorizer, so a test never changes the warm app's bindings. */
function hostAuthorizer(?Ownership $ownership = null, ?BillingGate $billing = null, ?AgentPolicy $agent = null): HostAuthorizer
{
    return new HostAuthorizer(app(CapabilityRegistry::class), $ownership ?? new Ownership, $billing ?? new NoBilling, $agent ?? new NoAgentPolicy);
}

function authorizeAs(string $capability, object $actor, array $input = [], ?HostAuthorizer $authorizer = null): bool
{
    return ($authorizer ?? hostAuthorizer())->authorize($capability, $input, new CapabilityContext(caller: 'http', actor: $actor));
}

/** An ownership query that finds exactly the IDs given, without a database. */
function ownedIds(array $ids): Builder
{
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('whereKey')->andReturnUsing(function (mixed $id) use ($ids) {
        $found = Mockery::mock(Builder::class);
        $found->shouldReceive('exists')->andReturn(in_array($id, $ids, true));

        return $found;
    });

    return $query;
}

it('CAP-020 allows an invoke by a signed-in user', function () {
    expect(authorizeAs('account_show', authorizerUser()))->toBeTrue();
});

it('CAP-020 refuses an invoke with no signed-in user', function (mixed $context) {
    expect(hostAuthorizer()->authorize('account_show', [], $context))->toBeFalse();
})->with([
    'system actor' => fn () => new CapabilityContext(caller: 'job', actor: SystemActor::named('scheduler')),
    'actor that is not a user' => fn () => new CapabilityContext(caller: 'http', actor: new stdClass),
    'no context' => [null],
]);

it('CAP-031 refuses an excluded capability to the agent, whatever reached the bus', function (string $capability) {
    $authorizer = hostAuthorizer();

    expect($authorizer->authorize($capability, [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeFalse()
        ->and($authorizer->authorize('account_show', [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeTrue();
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

it('CAP-020 refuses a row ID the actor does not own, and reports it to the watch it runs in', function () {
    $ownership = new Ownership;
    $ownership->declare('account_show', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            return ['row_id' => ownedIds(['mine'])];
        }
    });

    $authorizer = hostAuthorizer($ownership);

    expect($ownership->watch(fn () => authorizeAs('account_show', authorizerUser(), ['row_id' => 'mine'], $authorizer)))->toBe([true, false])
        ->and($ownership->watch(fn () => authorizeAs('account_show', authorizerUser(), ['row_id' => 'theirs'], $authorizer)))->toBe([false, true])
        ->and($ownership->watch(fn () => authorizeAs('account_show', authorizerUser(), [], $authorizer)))->toBe([true, false]);
});

it('CAP-020 checks ownership before the token scope', function () {
    $ownership = new Ownership;
    $ownership->declare('account_show', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            return ['row_id' => ownedIds([])];
        }
    });

    expect($ownership->watch(fn () => authorizeAs('account_show', authorizerUser(['today_show']), ['row_id' => 'theirs'], hostAuthorizer($ownership))))->toBe([false, true]);
});

it('CAP-023 allows a token only the capabilities in its abilities', function (array $abilities, bool $allowed) {
    expect(authorizeAs('account_show', authorizerUser($abilities)))->toBe($allowed);
})->with([
    'named' => [['account_show'], true],
    'everything' => [['*'], true],
    'not named' => [['today_show'], false],
]);

it('CAP-031 never lets a token call an exclusion, even with Everything or by name', function (string $capability) {
    expect(authorizeAs($capability, authorizerUser(['*'])))->toBeFalse()
        ->and(authorizeAs($capability, authorizerUser([$capability])))->toBeFalse()
        ->and(HostAuthorizer::tokenMayCall($capability))->toBeFalse();
})->with([...array_keys(Exclusions::NAMES), 'billing_checkout_start', 'billing_portal_open']);

it('CAP-031 still lets a session call a registered exclusion', function (string $capability) {
    expect(authorizeAs($capability, authorizerUser()))->toBeTrue();
})->with(['token_create', 'token_revoke']);

it('CAP-023 does not limit a session, whose token is transient', function () {
    $user = authorizerUser()->withAccessToken(new TransientToken);

    expect(authorizeAs('account_show', $user))->toBeTrue();
});

it('CAP-020 asks billing, then the agent policy', function () {
    $asked = new ArrayObject;
    $billing = new class($asked) implements BillingGate
    {
        public function __construct(public ArrayObject $asked) {}

        public function allows(User $user, CapabilityDefinition $capability): bool
        {
            $this->asked[] = "billing {$capability->name}";

            return $capability->name !== 'token_list';
        }
    };
    $agent = new class($asked) implements AgentPolicy
    {
        public function __construct(public ArrayObject $asked) {}

        public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool
        {
            $this->asked[] = "agent {$capability->name}";

            return $capability->name !== 'token_revoke';
        }
    };
    $authorizer = hostAuthorizer(billing: $billing, agent: $agent);

    expect(authorizeAs('account_show', authorizerUser(), authorizer: $authorizer))->toBeTrue()
        ->and(authorizeAs('token_list', authorizerUser(), authorizer: $authorizer))->toBeFalse()
        ->and(authorizeAs('token_revoke', authorizerUser(), authorizer: $authorizer))->toBeFalse()
        ->and($asked->getArrayCopy())->toBe(['billing account_show', 'agent account_show', 'billing token_list', 'billing token_revoke', 'agent token_revoke']);
});

it('CAP-020 allows everything by default where billing and the agent policy are not set up', function () {
    expect(app(BillingGate::class))->toBeInstanceOf(NoBilling::class)
        ->and(app(AgentPolicy::class))->toBeInstanceOf(NoAgentPolicy::class)
        ->and(app(BillingGate::class)->allows(authorizerUser(), app(CapabilityRegistry::class)->get('account_show')))->toBeTrue()
        ->and(app(AgentPolicy::class)->allows(app(CapabilityRegistry::class)->get('account_show'), [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeTrue();
});
