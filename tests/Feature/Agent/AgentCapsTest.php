<?php

use App\Http\Middleware\AgentGate;
use App\Models\ClearedCoachTurn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Contracts\TurnClaim;
use Rawphp\CapabilitiesAi\Models\Conversation;
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
            'on' => true,
            'conversation_ulid' => Conversation::query()->where('user_id', (string) $user->id)->value('ulid'),
            'turns_left' => 2,
            'tokens_left' => 992_000,
            'at_cap' => false,
            'resets_at' => null,
            'notice' => '2 Coach messages left today.',
            'notice_status' => ['text' => '2 Coach messages left today.', 'tone' => 'info'],
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

it('AGT-005 checks and stores a Coach message under one lock per user, so parallel posts cannot all pass', function (array $caps) {
    // The lock's wait runs on the clock, so it must move here.
    CarbonImmutable::setTestNow();
    config($caps);
    $user = User::factory()->create();
    $other = User::factory()->create();
    // Another request of this user is between the checks and the store, and does not finish.
    $held = Cache::lock(AgentGate::LOCK.$user->id, 30);
    $held->get();

    $started = microtime(true);
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', 'Your last Coach message is still being sent. Try again in a moment.');
    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(AgentGate::WAIT_SECONDS - 0.5);
    $this->actingAs($other)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    expect(Turn::query()->count())->toBe(1);

    $held->release();
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    // The gate let its own lock go: the next request can take it.
    expect(Cache::lock(AgentGate::LOCK.$user->id, 30)->get())->toBeTrue();
})->with([
    'daily cap' => [['agent.turns_per_day' => 10]],
    'monthly token cap' => [['agent.tokens_per_month' => 1_000_000]],
]);

it('AGT-005 with no cap takes parallel Coach messages: no lock, no refusal', function () {
    $user = User::factory()->create();
    $held = Cache::lock(AgentGate::LOCK.$user->id, 30);
    $held->get();

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    $held->release();
});

it('AGT-005 under a token cap holds a message while a turn runs, and takes it once the reaper fails a dead one', function () {
    config(['agent.tokens_per_month' => 1_000_000]);
    $user = User::factory()->create();
    $claim = app(TurnClaim::class);
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    $turn = Turn::query()->latest('id')->firstOrFail();
    $claim->claim($turn->ulid, 'worker-that-dies');

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hello?'])
        ->assertStatus(429)
        ->assertJsonPath('error.message', 'The Coach is still answering your last message.');

    // The worker died mid-turn; the reaper fails the turn through the bound claim.
    expect($claim->failStaleRunning(CarbonImmutable::now()->addMinute(), 'stale', CarbonImmutable::now()))->toBe([$turn->ulid]);

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hello?'])->assertCreated();
    // The dead worker recorded no usage, so the reaped turn counts no tokens.
    expect($turn->fresh()->getAttribute('tokens'))->toBe(0);
});

it('AGT-005 SET-012 runs the month in the app timezone and the day in the user\'s time zone', function () {
    $zone = date_default_timezone_get();
    date_default_timezone_set('Australia/Sydney');
    config(['app.timezone' => 'Australia/Sydney', 'agent.turns_per_day' => 3, 'agent.tokens_per_month' => 10_000]);
    try {
        $user = User::factory()->create();
        $user->forceFill(['time_zone' => 'Australia/Sydney'])->save();
        // Local times: 00:10 today and 23:50 yesterday are 13:10 and 12:50 on 29 Nov in UTC.
        pastTurns($user, [3_000, 3_000], at: '2026-11-30 00:10:00');
        pastTurns($user, [4_000], at: '2026-11-29 23:50:00');
        pastTurns($user, [50_000], at: '2026-10-31 23:50:00');

        CarbonImmutable::setTestNow('2026-11-30 23:30:00');
        $this->actingAs($user)->postJson('/capabilities/agent_status_show')
            ->assertOk()
            ->assertJsonPath('data.turns_left', 1)
            ->assertJsonPath('data.tokens_left', 0)
            ->assertJsonPath('data.resets_at', '2026-12-01T00:00:00+11:00')
            ->assertJsonPath('data.notice', 'You\'ve reached this month\'s Coach limit. It resets at 00:00 AEDT on 1 Dec. Everything else still works.')
            ->assertJsonPath('data.notice_status.text', 'You\'ve reached this month\'s Coach limit. It resets at 00:00 AEDT on 1 Dec. Everything else still works.')
            ->assertJsonPath('data.notice_status.tone', 'warning');
        $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertStatus(429);

        // Ten minutes after local midnight, a new day and a new month.
        CarbonImmutable::setTestNow('2026-12-01 00:10:00');
        $this->actingAs($user)->postJson('/capabilities/agent_status_show')
            ->assertOk()
            ->assertJsonPath('data.turns_left', 3)
            ->assertJsonPath('data.tokens_left', 10_000)
            ->assertJsonPath('data.at_cap', false)
            ->assertJsonPath('data.notice', null)
            ->assertJsonPath('data.notice_status', null);
        $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertCreated();
    } finally {
        date_default_timezone_set($zone);
    }
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

it('AGT-005 AGT-020 keeps a cleared conversation\'s turns and tokens in the day\'s and month\'s caps', function () {
    config(['agent.turns_per_day' => 3, 'agent.tokens_per_month' => 10_000]);
    $user = User::factory()->create();
    pastTurns($user, [2_000, 2_000]);
    pastTurns($user, [3_000], at: '2026-11-01 08:00:00');
    pastTurns($user, [50_000], at: '2026-10-31 08:00:00');
    $before = $this->actingAs($user)->postJson('/capabilities/agent_status_show')->assertOk()->json('data');
    expect($before)->toMatchArray(['turns_left' => 1, 'tokens_left' => 3_000]);

    $this->actingAs($user)->postJson('/capabilities/coach_conversation_clear', [], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    expect(Turn::query()->count())->toBe(0)
        ->and($this->actingAs($user)->postJson('/capabilities/agent_status_show')->assertOk()->json('data'))
        ->toMatchArray(['turns_left' => 1, 'tokens_left' => 3_000, 'conversation_ulid' => null]);

    pastTurns($user, [3_000]);
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});

it('AGT-005 prunes a cleared turn once its month is over', function () {
    $user = User::factory()->create();
    ClearedCoachTurn::query()->create(['user_id' => $user->id, 'tokens' => 100, 'created_at' => '2026-10-31 23:00:00']);
    $kept = ClearedCoachTurn::query()->create(['user_id' => $user->id, 'tokens' => 100, 'created_at' => '2026-11-01 00:00:00']);

    Artisan::call('model:prune', ['--model' => [ClearedCoachTurn::class]]);

    expect(ClearedCoachTurn::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('SET-012 counts the Coach\'s turns today from the user\'s local midnight', function (string $zone, int $turnsLeft, bool $atCap, ?string $resetsAt, int $status) {
    config(['agent.turns_per_day' => 3]);
    $user = User::factory()->create();
    $user->forceFill(['time_zone' => $zone])->save();
    // 19:00 on 2 Nov and 00:30 on 3 Nov in Brisbane; it is 01:30 on 3 Nov there now.
    pastTurns($user, [100, 100]);
    pastTurns($user, [100], at: '2026-11-02 14:30:00');

    $this->actingAs($user)->postJson('/capabilities/agent_status_show')
        ->assertOk()
        ->assertJsonPath('data.turns_left', $turnsLeft)
        ->assertJsonPath('data.at_cap', $atCap)
        ->assertJsonPath('data.resets_at', $resetsAt);
    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])->assertStatus($status);
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', 2, false, null, 201],
    'UTC' => ['UTC', 0, true, '2026-11-03T00:00:00+00:00', 429],
]);

it('SET-012 says the Coach\'s daily cap resets at the user\'s local midnight', function (string $zone, string $resetsAt, string $message) {
    config(['agent.turns_per_day' => 1]);
    $user = User::factory()->create();
    $user->forceFill(['time_zone' => $zone])->save();
    pastTurns($user, [100], at: '2026-11-02 14:30:00');

    $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertStatus(429)
        ->assertJsonPath('error.resets_at', $resetsAt)
        ->assertJsonPath('error.message', $message);
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', '2026-11-04T00:00:00+10:00', 'You\'ve reached today\'s Coach limit. It resets at 00:00 AEST on 4 Nov. Everything else still works.'],
    'UTC' => ['UTC', '2026-11-03T00:00:00+00:00', 'You\'ve reached today\'s Coach limit. It resets at 00:00 UTC on 3 Nov. Everything else still works.'],
]);
