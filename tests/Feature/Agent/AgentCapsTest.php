<?php

use App\Http\Middleware\AgentGate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Contracts\TurnClaim;
use Rawphp\CapabilitiesAi\Models\Turn;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-02 15:30:00');
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Turns the user has already run, each finished through the bound claim with its LLM token use.
 *
 * @param  list<int>  $tokens  tokens per turn
 */
function pastTurns(User $user, array $tokens, string $at = '2026-11-02 09:00:00'): void
{
    $store = app(ConversationStore::class);
    $claim = app(TurnClaim::class);
    $conversation = $store->createConversation(strtoupper(bin2hex(random_bytes(13))), null, (string) $user->id);
    foreach ($tokens as $used) {
        $turn = $store->createQueuedTurn($conversation, strtoupper(bin2hex(random_bytes(13))));
        $claim->claim($turn->ulid, 'test-worker');
        $claim->complete($turn->ulid, [['latency_ms' => 900, 'input_tokens' => intdiv($used, 2), 'output_tokens' => $used - intdiv($used, 2)]]);
        Turn::query()->whereKey($turn->getKey())->update(['created_at' => $at]);
    }
}

it('AGT-005 refuses a new Coach message at the daily cap, says when it resets, and stores nothing', function () {
    config(['agent.turns_per_day' => 3]);
    $user = User::factory()->create();
    pastTurns($user, [100, 100]);
    pastTurns(User::factory()->create(), [100, 100, 100]);
    pastTurns($user, [100], at: '2026-11-01 23:00:00');

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi again'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', 'You\'ve reached today\'s Coach limit. It resets at 00:00 UTC on 3 Nov. Everything else still works.')
        ->assertJsonPath('error.resets_at', '2026-11-03T00:00:00+00:00');

    expect(Turn::query()->count())->toBe(7);
});

it('AGT-005 refuses a new Coach message once the month\'s tokens are used', function () {
    config(['agent.tokens_per_month' => 10_000]);
    $user = User::factory()->create();
    pastTurns($user, [6_000], at: '2026-11-01 08:00:00');
    pastTurns($user, [4_000]);
    pastTurns($user, [50_000], at: '2026-10-31 08:00:00');

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertStatus(429)
        ->assertJsonPath('error.resets_at', '2026-12-01T00:00:00+00:00');
});

it('AGT-005 says how much is left near the cap, and the chat still takes the message', function () {
    config(['agent.turns_per_day' => 10, 'agent.tokens_per_month' => 1_000_000]);
    $user = User::factory()->create();
    pastTurns($user, array_fill(0, 8, 1_000));

    $this->actingAs($user)->postJson('/capabilities/agent_status_show')
        ->assertOk()
        ->assertJsonPath('data', [
            'enabled' => true,
            'turns_left' => 2,
            'tokens_left' => 992_000,
            'at_cap' => false,
            'resets_at' => null,
            'notice' => '2 Coach messages left today.',
        ]);

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
});

it('AGT-005 under a token cap takes one Coach message at a time, so a month ends at most one turn over', function () {
    config(['agent.tokens_per_month' => 1_000_000]);
    $user = User::factory()->create();
    pastTurns(User::factory()->create(), [10]);

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'And another thing'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', 'The Coach is still answering your last message.');
    expect(Turn::query()->count())->toBe(2);

    // Once the turn ends, the next message is taken.
    $turn = Turn::query()->latest('id')->firstOrFail();
    app(TurnClaim::class)->claim($turn->ulid, 'test-worker');
    app(TurnClaim::class)->complete($turn->ulid, []);
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'And another thing'])->assertCreated();
});

it('AGT-005 checks and stores a Coach message under one lock per user, so parallel posts cannot all pass', function () {
    config(['agent.tokens_per_month' => 1_000_000]);
    $user = User::factory()->create();
    $other = User::factory()->create();
    // Another request of this user is between the checks and the store.
    $held = Cache::lock(AgentGate::LOCK.$user->id, 30);
    $held->get();

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertStatus(429)
        ->assertJsonPath('error.message', 'The Coach is still answering your last message.');
    $this->actingAs($other)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    expect(Turn::query()->count())->toBe(1);

    $held->release();
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    // The gate let its own lock go: the next request can take it.
    expect(Cache::lock(AgentGate::LOCK.$user->id, 30)->get())->toBeTrue();
});

it('AGT-005 with no cap takes parallel Coach messages: no lock, no refusal', function () {
    $user = User::factory()->create();
    $held = Cache::lock(AgentGate::LOCK.$user->id, 30);
    $held->get();

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    $held->release();
});

it('AGT-005 without a token cap takes a message while another turn runs', function () {
    config(['agent.turns_per_day' => 10]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'And another thing'])->assertCreated();
});

it('AGT-005 still answers every capability at the cap', function () {
    config(['agent.turns_per_day' => 1]);
    $user = User::factory()->create();
    pastTurns($user, [10]);

    $this->actingAs($user)->postJson('/capabilities/account_show')->assertOk();
    $this->actingAs($user)->postJson('/capabilities/agent_status_show')
        ->assertOk()
        ->assertJsonPath('data.at_cap', true)
        ->assertJsonPath('data.resets_at', '2026-11-03T00:00:00+00:00');
});

it('AGT-002 says the agent is off without the anthropic driver and an API key, and refuses chat', function (string $driver, ?string $key) {
    config(['capabilities-ai.llm.driver' => $driver, 'capabilities-ai.llm.anthropic.api_key' => $key]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/agent_status_show')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertForbidden()
        ->assertJsonPath('error.message', 'The Coach is off on this instance.');

    expect(Turn::query()->count())->toBe(0);
})->with([
    'no key' => ['anthropic', null],
    'empty key' => ['anthropic', ''],
    'fake driver' => ['fake', 'test-key'],
]);

it('AGT-002 keeps the package\'s chat routes signed in only', function () {
    $this->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertUnauthorized();
    $this->postJson('/capabilities/agent_status_show')->assertUnauthorized();
});
