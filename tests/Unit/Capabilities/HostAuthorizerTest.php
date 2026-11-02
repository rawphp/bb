<?php

use App\Agent\ConfirmationPolicy;
use App\Agent\Exclusions;
use App\Capabilities\HostAuthorizer;
use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\HostDefinition;
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
    return new HostAuthorizer(app(CapabilityRegistry::class), $ownership ?? new Ownership, $billing ?? new NoBilling, $agent ?? app(AgentPolicy::class));
}

function authorizeAs(string $capability, object $actor, array $input = [], ?HostAuthorizer $authorizer = null): bool
{
    return ($authorizer ?? hostAuthorizer())->authorize($capability, $input, new CapabilityContext(caller: 'http', actor: $actor));
}

/** An ownership query that finds exactly the IDs given, without a database. */
function ownedIds(array $ids): Builder
{
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('whereKey')->andReturnUsing(function (array $keys) use ($ids) {
        $found = Mockery::mock(Builder::class);
        $found->shouldReceive('count')->andReturn(count(array_intersect($keys, $ids)));

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

it('CAP-020 checks an ID inside each item of a list of objects', function (array $entries, bool $allowed) {
    $ownership = new Ownership;
    $ownership->declare('account_show', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            return ['entries.*.recipe_id' => ownedIds(['a', 'b'])];
        }
    });

    expect(authorizeAs('account_show', authorizerUser(), ['entries' => $entries], hostAuthorizer($ownership)))->toBe($allowed);
})->with([
    'all mine' => [[['recipe_id' => 'a'], ['recipe_id' => 'b']], true],
    'one without a recipe' => [[['recipe_id' => 'a'], ['food_id' => 'f']], true],
    'one not mine' => [[['recipe_id' => 'a'], ['recipe_id' => 'theirs']], false],
    'not an ID' => [[['recipe_id' => ['a']]], false],
]);

it('CAP-020 checks every ID in a list, and lets an empty list through', function (mixed $ids, bool $allowed) {
    $ownership = new Ownership;
    $ownership->declare('account_show', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            return ['row_ids' => ownedIds(['a', 'b'])];
        }
    });

    expect(authorizeAs('account_show', authorizerUser(), ['row_ids' => $ids], hostAuthorizer($ownership)))->toBe($allowed);
})->with([
    'all mine' => [['a', 'b'], true],
    'mine, repeated' => [['a', 'a', 'b'], true],
    'empty' => [[], true],
    'one not mine' => [['a', 'theirs'], false],
    'not an ID' => [['a', ['b']], false],
]);

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

        public function allowsCoach(User $user): bool
        {
            return true;
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

it('CAP-020 binds the no-billing gate and the confirmation policy, and both allow a call that is not destructive', function () {
    expect(app(BillingGate::class))->toBeInstanceOf(NoBilling::class)
        ->and(app(AgentPolicy::class))->toBeInstanceOf(ConfirmationPolicy::class)
        ->and(app(BillingGate::class)->allows(authorizerUser(), app(CapabilityRegistry::class)->get('account_show')))->toBeTrue()
        ->and(app(AgentPolicy::class)->allows(app(CapabilityRegistry::class)->get('account_show'), [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeTrue();
});

it('AGT-010 refuses a destructive capability to the agent when the turn carries no confirmation', function (string $capability) {
    $registry = app(CapabilityRegistry::class);
    $definition = $registry->get($capability);
    $policy = app(AgentPolicy::class);

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and($policy->allows($definition, [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeFalse()
        ->and(hostAuthorizer()->authorize($capability, [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeFalse();

    foreach (['http', 'mcp', 'cli', 'job'] as $caller) {
        expect($policy->allows($definition, [], new CapabilityContext(caller: $caller, actor: authorizerUser())))->toBeTrue();
    }
})->with(['mesocycle_end', 'mesocycle_copy']);

it('AGT-010 still lets the agent call a write that is not destructive', function () {
    $definition = app(CapabilityRegistry::class)->get('mesocycle_length_set');

    expect(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(app(AgentPolicy::class)->allows($definition, [], new CapabilityContext(caller: 'agent', actor: authorizerUser())))->toBeTrue();
});
