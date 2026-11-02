<?php

use App\Agent\Exclusions;
use App\Capabilities\HostAuthorizer;
use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\HostBus;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\NoBilling;
use App\Capabilities\Support\Ownership;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Tokens\TokenCatalog;
use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Adapters\Mcp\McpCredential;
use Rawphp\Capabilities\Adapters\Mcp\McpToolAdapter;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Models\Turn;
use Tests\Support\Fixtures\Rail\TallyInput;

const TOKEN_ID = '01JBX8Z6Q7M0V2K3N4P5R6S7T8';

/** Creates a token for the user over HTTP, signed in, and returns its secret. */
function createToken(User $user, string $scope, array $capabilities = [], string $id = TOKEN_ID): string
{
    $response = test()->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => $id,
        'name' => 'Laptop MCP',
        'expires_in_days' => 90,
        'scope' => $scope,
        'capabilities' => array_map(fn (string $name) => ['name' => $name], $capabilities),
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    // The next request authenticates by its bearer token alone.
    Auth::forgetGuards();

    return $response->json('data.token.plain_text');
}

function withToken(string $secret): mixed
{
    return test()->withHeader('Authorization', "Bearer {$secret}");
}

it('CAP-021 creates a token that is shown once and lists it without its secret', function () {
    $user = User::factory()->create();

    $create = $this->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => TOKEN_ID,
        'name' => 'Laptop MCP',
        'expires_in_days' => 30,
        'scope' => 'read_only',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['token_list{}'])
        ->assertJsonPath('data.token.id', TOKEN_ID)
        ->assertJsonPath('data.token.scope_text', 'Read only');

    expect($create->json('data.token.plain_text'))->toStartWith(TOKEN_ID.'|');

    $list = $this->actingAs($user)->postJson('/capabilities/token_list')
        ->assertOk()
        ->assertJsonPath('data.tokens.0.id', TOKEN_ID)
        ->assertJsonPath('data.tokens.0.last_used_text', 'Never used');

    expect($list->getContent())->not->toContain(Str::after($create->json('data.token.plain_text'), '|'))
        ->and(array_column($list->json('data.capabilities'), 'name'))->toContain('account_show', 'token_list')
        ->not->toContain('token_create', 'token_revoke');
    assertMatchesScreenFixture('token_list', $list->json('data'));
});

it('CAP-021 gives a Read only token every read and no write', function () {
    createToken(User::factory()->create(), 'read_only');

    $registry = app(CapabilityRegistry::class);
    $reads = array_keys(array_filter($registry->all(), fn ($definition) => $definition->readOnly));
    sort($reads);

    expect(PersonalAccessToken::query()->findOrFail(TOKEN_ID)->abilities)->toBe($reads)->toContain('account_show', 'token_list');
});

it('CAP-023 stores each scope as capability names', function (string $scope, array $picked, array $abilities) {
    $user = User::factory()->create();

    createToken($user, $scope, $picked);

    expect(PersonalAccessToken::query()->findOrFail(TOKEN_ID)->abilities)->toBe($abilities);
})->with([
    'everything' => ['everything', [], ['*']],
    'health bridge' => ['health_bridge', [], ['weigh_in_log', 'steps_log']],
    'custom' => ['custom', ['token_list'], ['token_list']],
]);

it('CAP-021 refuses token picks that do not fit the scope, naming the field, and makes no token', function (string $scope, array $picked, string $message) {
    $this->actingAs(User::factory()->create())->postJson('/capabilities/token_create', [
        'id' => TOKEN_ID,
        'name' => 'Sneaky',
        'expires_in_days' => 30,
        'scope' => $scope,
        'capabilities' => array_map(fn (string $name) => ['name' => $name], $picked),
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('error.violations', [['field' => 'capabilities', 'message' => $message]])
        ->assertJsonPath('meta.stages', fn (array $stages) => ! in_array('run', $stages, true));

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'custom, a capability no token may call' => ['custom', ['token_list', 'token_create'], 'A token can\'t use token_create.'],
    'custom, an unknown capability' => ['custom', ['no_such_thing'], 'A token can\'t use no_such_thing.'],
    'custom, nothing picked' => ['custom', [], 'Pick at least one action for a custom token.'],
    'not custom, picks sent' => ['read_only', ['token_list'], 'Only a custom token takes a list of actions.'],
]);

it('CAP-023 lets a token call the capabilities in its scope', function () {
    $secret = createToken(User::factory()->create(['name' => 'Sam Lifter']), 'read_only');

    withToken($secret)->postJson('/capabilities/account_show')
        ->assertOk()
        ->assertJsonPath('data.name', 'Sam Lifter');

    expect(PersonalAccessToken::query()->findOrFail(TOKEN_ID)->last_used_at)->not->toBeNull();
});

it('CAP-023 refuses a token a capability outside its scope with 403', function () {
    $secret = createToken(User::factory()->create(), 'custom', ['token_list']);

    withToken($secret)->postJson('/capabilities/account_show')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'You can\'t do that.');
});

it('CAP-023 never lets a token create or revoke tokens', function () {
    $secret = createToken(User::factory()->create(), 'everything');

    withToken($secret)->postJson('/capabilities/token_revoke', ['id' => TOKEN_ID], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden();

    expect(PersonalAccessToken::query()->whereKey(TOKEN_ID)->exists())->toBeTrue();
});

it('CAP-021 makes a token last the days picked, and stops it after', function (int $days) {
    $this->freezeTime();
    $user = User::factory()->create();

    $create = $this->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => TOKEN_ID,
        'name' => 'Laptop MCP',
        'expires_in_days' => $days,
        'scope' => 'everything',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    Auth::forgetGuards();
    $ends = now()->addDays($days);

    expect(PersonalAccessToken::query()->findOrFail(TOKEN_ID)->expires_at->toDateTimeString())->toBe($ends->toDateTimeString())
        ->and($create->json('data.token.expires_text'))->toBe('Expires '.$ends->format('j F Y'));

    $this->travel($days - 1)->days();
    withToken($create->json('data.token.plain_text'))->postJson('/capabilities/account_show')->assertOk();
    Auth::forgetGuards();

    $this->travel(2)->days();
    withToken($create->json('data.token.plain_text'))->postJson('/capabilities/account_show')->assertUnauthorized();
})->with([30, 90, 365]);

it('CAP-021 revokes a token, which stops working at once', function () {
    $user = User::factory()->create();
    $secret = createToken($user, 'everything');

    $this->actingAs($user)->postJson('/capabilities/token_revoke', ['id' => TOKEN_ID], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['token_list{}']);
    Auth::forgetGuards();

    withToken($secret)->postJson('/capabilities/account_show')->assertUnauthorized();
});

it('CAP-020 answers another user\'s token ID with 404', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    createToken($owner, 'everything');

    $this->actingAs($other)->postJson('/capabilities/token_revoke', ['id' => TOKEN_ID], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'We couldn\'t find that.');

    expect(PersonalAccessToken::query()->whereKey(TOKEN_ID)->exists())->toBeTrue();
});

it('CAP-019 answers a create under another user\'s token ULID with 404', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    createToken($owner, 'everything');

    $this->actingAs($other)->postJson('/capabilities/token_create', [
        'id' => TOKEN_ID,
        'name' => 'Mine now',
        'expires_in_days' => 30,
        'scope' => 'everything',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertNotFound();

    expect(PersonalAccessToken::query()->findOrFail(TOKEN_ID)->name)->toBe('Laptop MCP');
});

it('ACC-005 deletes a user\'s tokens with the account', function () {
    $user = User::factory()->create();
    createToken($user, 'everything');

    $user->delete();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('CAP-021 answers a second create under the same token ULID with 409', function () {
    $user = User::factory()->create();
    createToken($user, 'read_only');

    $this->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => TOKEN_ID,
        'name' => 'Laptop again',
        'expires_in_days' => 30,
        'scope' => 'everything',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    expect(PersonalAccessToken::query()->findOrFail(TOKEN_ID))
        ->name->toBe('Laptop MCP')
        ->scope->toBe(TokenScope::ReadOnly);
});

it('CAP-014 replays a token create without its secret, and stores no secret', function () {
    $user = User::factory()->create();
    $body = ['id' => TOKEN_ID, 'name' => 'Laptop MCP', 'expires_in_days' => 30, 'scope' => 'everything', 'capabilities' => []];
    $key = ['Idempotency-Key' => (string) Str::ulid()];

    $first = $this->actingAs($user)->postJson('/capabilities/token_create', $body, $key)->assertOk();
    $secret = Str::after($first->json('data.token.plain_text'), '|');

    $this->actingAs($user)->postJson('/capabilities/token_create', $body, $key)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.token.id', TOKEN_ID)
        ->assertJsonPath('data.token.plain_text', null)
        ->assertJsonPath('data.invalidates', ['token_list{}']);

    expect($secret)->not->toBe('')
        ->and(json_encode(DB::table('capabilities_idempotency')->get()))->not->toContain($secret)
        ->and(json_encode(DB::table('audit_records')->get()))->not->toContain($secret)
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('CAP-023 keeps every token out of the Coach, whose turns no scope limits', function () {
    $user = User::factory()->create();
    $secret = createToken($user, 'everything');

    withToken($secret)->postJson('/capabilities-ai/chat/messages', ['content' => 'Delete my plan'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
    withToken($secret)->getJson('/capabilities-ai/chat/conversations/'.TOKEN_ID)->assertForbidden();

    expect(Turn::query()->count())->toBe(0);
});

it('CAP-023 holds a token to its scope on the MCP surface', function () {
    $user = User::factory()->create(['name' => 'Sam Lifter']);
    $secret = createToken($user, 'custom', ['token_list']);

    // The user as auth:sanctum resolves it for an MCP request with this bearer.
    $request = Request::create('/mcp/bodybuilder', 'POST', server: ['HTTP_AUTHORIZATION' => "Bearer {$secret}"]);
    $bearer = Auth::guard('sanctum')->setRequest($request)->user();
    $mcp = fn (string $name, array $input = []) => app(McpToolAdapter::class)->handle(
        $name,
        $input,
        McpCredential::userPat($bearer),
        ['profile' => ['token_list', 'account_show', 'token_create'], 'idempotency_key' => (string) Str::ulid()],
    );

    expect($bearer)->toBeInstanceOf(User::class)
        ->and($mcp('token_list')->isOk())->toBeTrue()
        ->and($mcp('account_show')->errorCode())->toBe('forbidden')
        ->and($mcp('token_create', ['id' => (string) Str::ulid(), 'name' => 'Minted', 'expires_in_days' => 30, 'scope' => 'everything', 'capabilities' => []])->errorCode())->toBe('forbidden')
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('CAP-031 refuses an Everything token every exclusion, and never offers one for a custom scope', function () {
    $secret = createToken(User::factory()->create(), 'everything');

    withToken($secret)->postJson('/capabilities/account_delete', ['confirmation' => 'DELETE'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(app(CapabilityRegistry::class)->has('account_delete') ? 403 : 404);

    $offered = app(TokenCatalog::class)->names();
    foreach (app(CapabilityRegistry::class)->all() as $name => $definition) {
        expect(in_array($name, $offered, true))->toBe(Exclusions::reason($name) === null && in_array('http', $definition->surfaces, true), $name);
    }
});

it('INT-001 names, for a Health bridge token, the two writes the weigh-in contracts define', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (TokenScope::HEALTH_BRIDGE as $name) {
        expect(base_path("tests/fixtures/schemas/{$name}.input.json"))->toBeFile();
        if ($registry->has($name)) {
            expect(app(TokenCatalog::class)->names())->toContain($name)
                ->and($registry->get($name)->readOnly)->toBeFalse();
        }
    }
});

/**
 * Every row-ID field in an input schema, as the dotted path Ownership reads: nested
 * objects by name, each item of a list as `*` (`entries.*.recipe_id`).
 *
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function rowIdFields(array $schema, string $prefix = ''): array
{
    $fields = [];
    foreach ($schema['properties'] ?? [] as $name => $property) {
        $path = $prefix.$name;
        if ($name === 'id' || str_ends_with($name, '_id') || str_ends_with($name, '_ids')) {
            $fields[] = $path;
        }
        if (is_array($property['properties'] ?? null)) {
            array_push($fields, ...rowIdFields($property, $path.'.'));
        }
        if (is_array($property['items']['properties'] ?? null)) {
            array_push($fields, ...rowIdFields($property['items'], $path.'.*.'));
        }
    }

    return $fields;
}

it('CAP-020 makes every capability that takes an existing row\'s ID check its owner, nested fields too', function () {
    // Fields that name no row the user owns, each with why. A create takes a new row's
    // client-made ULID (CAP-019) and checks its owner itself.
    // mesocycle_copy's new draft is such a create (ownedOrNew); a day_id names a new day inside a mesocycle
    // whose own ID is checked. custom_food_save and off_plan_log create or edit under the client's ULID (ownedOrNew);
    // saved_meal_save creates under it and returns the user's existing one unchanged. workout_start's workout_id names
    // a session that may have no row yet (OFF-021); the action finds it among the user's mesocycles or answers 404.
    $exempt = ['token_create' => ['id'], 'mesocycle_copy' => ['mesocycle_id'], 'mesocycle_day_add' => ['day_id'], 'custom_food_save' => ['id'], 'off_plan_log' => ['id'], 'saved_meal_save' => ['id'], 'workout_start' => ['workout_id']];
    $declared = (fn (): array => $this->capabilities)->call(app(Ownership::class));

    foreach (app(CapabilityRegistry::class)->all() as $name => $definition) {
        $fields = rowIdFields($definition->inputSchema() ?? []);
        $owned = isset($declared[$name]) ? array_keys($declared[$name]->owned(new User)) : [];

        expect(array_values(array_diff($fields, $owned, $exempt[$name] ?? [])))->toBe([], "{$name} takes a row ID it neither declares in OwnsInput nor exempts here");
    }
});

it('CAP-020 finds a row ID nested in an object or in each item of a list', function () {
    expect(rowIdFields([
        'properties' => [
            'id' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'meal_ids' => ['type' => 'array'],
            'day' => ['type' => 'object', 'properties' => ['plan_id' => ['type' => 'string']]],
            'entries' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'recipe_id' => ['type' => 'string'],
                'grams' => ['type' => 'number'],
            ]]],
        ],
    ]))->toBe(['id', 'meal_ids', 'day.plan_id', 'entries.*.recipe_id']);
});

it('CAP-020 refuses another user\'s token ID in the authorizer, before revoke runs', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    createToken($owner, 'everything');

    $response = $this->actingAs($other)->postJson('/capabilities/token_revoke', ['id' => TOKEN_ID], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    // The stages stop at authorize: revoke's own owner query never got the chance to hide a gap.
    expect($response->json('meta.stages'))->toContain('authorize')->not->toContain('run')
        ->and(PersonalAccessToken::query()->whereKey(TOKEN_ID)->exists())->toBeTrue();
});

it('CAP-021 mints every token through token_create, so each has a scope', function () {
    $user = User::factory()->create();

    expect(fn () => $user->createToken('cli'))->toThrow(LogicException::class);

    // The host binds no AuthTokenIssuer, so the package's sign-in routes mint nothing.
    foreach (['/capabilities/auth/token', '/capabilities/auth/device'] as $route) {
        $this->postJson($route, ['email' => $user->email])->assertJsonPath('error.code', 'not_configured');
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('INT-001 lets a Health bridge token post a weigh-in and steps, and nothing else', function () {
    $user = User::factory()->create();
    createToken($user, 'health_bridge');
    $bearer = $user->withAccessToken(PersonalAccessToken::query()->findOrFail(TOKEN_ID));

    // A registry of its own, so no other test sees the stand-ins: until the weigh-in issue
    // registers them, a stand-in under each Health bridge name proves the scope.
    $registry = new CapabilityRegistry;
    foreach ([...TokenScope::HEALTH_BRIDGE, 'account_show', 'token_list'] as $name) {
        HostDefinition::write($name, affects: ['account_show'])
            ->input(TallyInput::class)
            ->run(fn (): WriteResult => new WriteResult(invalidates: []))
            ->register($registry);
    }
    $registry->withAuthorizerResolver(fn () => new HostAuthorizer($registry, new Ownership, new NoBilling, app(AgentPolicy::class)));
    $bus = new HostBus($registry, new Ownership);
    $invoke = fn (string $name) => $bus->invoke($name, ['amount' => 1], ['actor' => $bearer, 'caller' => 'http']);

    foreach (TokenScope::HEALTH_BRIDGE as $name) {
        expect($invoke($name)->isOk())->toBeTrue($name);
    }
    expect($invoke('account_show')->errorCode())->toBe('forbidden')
        ->and($invoke('token_list')->errorCode())->toBe('forbidden');
});

it('CAP-014 replays a token revoke retried under its key, though the token is gone', function () {
    $user = User::factory()->create();
    createToken($user, 'everything');
    $key = ['Idempotency-Key' => (string) Str::ulid()];

    $this->actingAs($user)->postJson('/capabilities/token_revoke', ['id' => TOKEN_ID], $key)->assertOk();

    // The replay is looked up before the authorizer, so the ownership check never
    // meets the deleted row.
    $this->actingAs($user)->postJson('/capabilities/token_revoke', ['id' => TOKEN_ID], $key)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.invalidates', ['token_list{}']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('CAP-021 lists only the user\'s own tokens, newest first', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $older = '01JBX8Z6Q7M0V2K3N4P5R6S7T1';
    $newer = '01JBX8Z6Q7M0V2K3N4P5R6S7T2';
    createToken($user, 'everything', id: $older);
    $this->travel(1)->minutes();
    createToken($user, 'read_only', id: $newer);
    createToken($other, 'everything', id: '01JBX8Z6Q7M0V2K3N4P5R6S7T3');

    $this->actingAs($user)->postJson('/capabilities/token_list')
        ->assertOk()
        ->assertJsonPath('data.tokens.*.id', [$newer, $older]);
});
