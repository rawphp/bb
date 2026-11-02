<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

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

it('CAP-021 refuses a custom token with a capability a token cannot call', function () {
    $this->actingAs(User::factory()->create())->postJson('/capabilities/token_create', [
        'id' => TOKEN_ID,
        'name' => 'Sneaky',
        'expires_in_days' => 30,
        'scope' => 'custom',
        'capabilities' => [['name' => 'token_create']],
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'A token can\'t use token_create.');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

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

it('CAP-021 stops an expired token', function () {
    $secret = createToken(User::factory()->create(), 'everything');
    $this->travel(91)->days();

    withToken($secret)->postJson('/capabilities/account_show')->assertUnauthorized();
});

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
