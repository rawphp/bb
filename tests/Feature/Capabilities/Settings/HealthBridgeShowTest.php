<?php

use App\Domain\Tokens\TokenScope;
use App\Enums\MeasurementSource;
use App\Models\PersonalAccessToken;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Tests\Support\ScreenFixture;

// The HTTP seam of health_bridge_show, and the bridge token end to end (INT-001, INT-004).
// The page's rules are unit tests (tests/Unit/Actions/Settings/ShowHealthBridgeTest.php).

beforeEach(fn () => config(['services.health_bridge.shortcut_url' => 'https://www.icloud.com/shortcuts/example']));

/** Sets up the bridge as Settings does, and returns the token's secret, shown once. */
function setUpBridge(User $user): string
{
    $secret = test()->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => (string) Str::ulid(),
        'name' => 'Health bridge',
        'expires_in_days' => 365,
        'scope' => 'health_bridge',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.token.plain_text');
    Auth::forgetGuards();

    return $secret;
}

/** What the Shortcut posts each morning: the latest body weight with its time, and yesterday's steps. */
function runShortcut(string $secret, string $weighedAt = '2026-11-02T06:00:00+00:00', string $day = '2026-11-01'): void
{
    test()->withToken($secret)->postJson('/capabilities/weigh_in_log', [
        'id' => (string) Str::ulid(),
        'weighed_at' => $weighedAt,
        'weight' => ['value' => 81.4, 'unit' => 'kg'],
    ])->assertOk();
    test()->withToken($secret)->postJson('/capabilities/steps_log', ['date' => $day, 'steps' => 8412])->assertOk();
    Auth::forgetGuards();
    test()->flushHeaders();
}

function healthBridgeShow(?User $user, array $input = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/health_bridge_show', $input);
}

it('INT-001 INT-004 matches the screen fixture once the bridge has posted today', function () {
    $user = User::factory()->create();
    $this->travelTo('2026-11-02T06:02:00Z');
    $secret = setUpBridge($user);
    runShortcut($secret);
    $this->travelTo('2026-11-02T09:00:00Z');

    $page = healthBridgeShow($user)->assertOk()->json('data');

    assertMatchesScreenFixture('health_bridge_show', $page);
    expect($page['status'])->toBe(['text' => 'Last post today, 06:02', 'tone' => 'good'])
        ->and($page['token']['last_used_text'])->toBe('Last used today, 06:02')
        ->and($page['last_post'])->toBe([
            'when_text' => 'Today, 06:02',
            'items' => [
                ['label' => 'Body weight', 'value' => '81.4 kg at 06:00'],
                ['label' => 'Steps', 'value' => '8,412 steps on 1 November'],
            ],
        ])
        ->and($page['shortcut_url'])->toBe('https://www.icloud.com/shortcuts/example')
        ->and(json_encode($page))->not->toContain(explode('|', $secret)[1]);
});

it('INT-004 matches the warning fixture when the bridge has stopped posting', function () {
    $user = User::factory()->create();
    $this->travelTo('2026-10-30T06:01:00Z');
    runShortcut(setUpBridge($user), '2026-10-30T06:00:00+00:00', '2026-10-29');
    $this->travelTo('2026-11-02T09:00:00Z');

    $page = healthBridgeShow($user)->assertOk()->json('data');

    // The warning state's shape, and the one output schema every state shares.
    expect(ScreenFixture::differences(ScreenFixture::load('health_bridge_show.warning'), $page))->toBe([]);
    assertMatchesScreenFixture('health_bridge_show', $page);
    expect($page['status'])->toBe(['text' => 'No posts for 3 days. Check the automation is still on.', 'tone' => 'warning']);
});

it('INT-001 matches the screen fixture before the bridge is set up, with nothing to show yet', function () {
    $this->travelTo('2026-11-02T09:00:00Z');

    $page = healthBridgeShow(User::factory()->create())->assertOk()->json('data');

    assertMatchesScreenFixture('health_bridge_show', $page);
    expect($page['status']['text'])->toBe('Not set up')
        ->and($page['token'])->toBeNull()
        ->and($page['last_post'])->toBeNull();
});

it('INT-001 lets the bridge token post a weigh-in and steps, and answers 403 to every other capability', function () {
    $user = User::factory()->create();
    $this->travelTo('2026-11-02T06:02:00Z');
    $secret = setUpBridge($user);

    runShortcut($secret);

    // Input is checked before the authorizer, so a capability whose empty input is invalid answers 422 over
    // HTTP; the authorizer, which an input that passes reaches, refuses every one of them as well.
    $others = array_diff(array_keys(app(CapabilityRegistry::class)->all()), TokenScope::HEALTH_BRIDGE);
    $bridge = $user->withAccessToken(PersonalAccessToken::query()->sole());
    $forbidden = [];
    foreach ($others as $name) {
        $status = $this->withToken($secret)->postJson("/capabilities/{$name}", [])->status();
        Auth::forgetGuards();
        expect($status)->toBeIn([403, 422], "{$name} answered {$status} to the bridge token")
            ->and(app(Authorizer::class)->authorize($name, [], new CapabilityContext(caller: 'http', actor: $bridge)))->toBeFalse($name);
        if ($status === 403) {
            $forbidden[] = $name;
        }
    }
    expect($forbidden)->toContain('health_bridge_show', 'account_show', 'token_list', 'activity_show')
        ->and(WeighIn::query()->count())->toBe(1)
        ->and(Step::query()->count())->toBe(1);
});

it('INT-005 lands what the bridge posts in the app\'s own weigh-ins and steps, with no other app to link', function () {
    $user = User::factory()->create();
    $this->travelTo('2026-11-02T06:02:00Z');

    runShortcut(setUpBridge($user));

    expect(WeighIn::query()->whereBelongsTo($user)->sole())->source->toBe(MeasurementSource::Shortcut)
        ->and(Step::query()->whereBelongsTo($user)->sole())->source->toBe(MeasurementSource::Shortcut)->count->toBe(8412);
});

it('INT-001 shows a user only their own bridge, never another user\'s token or posts', function () {
    $this->travelTo('2026-11-02T06:02:00Z');
    runShortcut(setUpBridge(User::factory()->create()));

    $page = healthBridgeShow(User::factory()->create())->assertOk()->json('data');

    expect($page['token'])->toBeNull()->and($page['last_post'])->toBeNull();
});

it('INT-001 answers 401 to health_bridge_show signed out', function () {
    healthBridgeShow(null)->assertUnauthorized();
});

it('INT-001 answers 422 to health_bridge_show given an input it does not take', function () {
    healthBridgeShow(User::factory()->create(), ['token' => 'x'])->assertUnprocessable()->assertJsonPath('ok', false);
});

it('CAP-011 refreshes the Health bridge page when its token is made or revoked', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    $made = $this->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => $id, 'name' => 'Health bridge', 'expires_in_days' => 365, 'scope' => 'health_bridge', 'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    $revoked = $this->actingAs($user)->postJson('/capabilities/token_revoke', ['id' => $id], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    expect($made->json('data.invalidates'))->toContain('health_bridge_show{}')
        ->and($revoked->json('data.invalidates'))->toContain('health_bridge_show{}');
});
