<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\TableNames;
use Rawphp\CapabilitiesAi\Models\Turn;
use Tests\Support\RecordingLlmClient;

// The user's one conversation (AGT-024), their own Coach switch (AGT-020), and
// clearing the conversation (AGT-020, AGT-024).

beforeEach(fn () => (new RecordingLlmClient([['content' => 'Hello.']]))->install());

function coachWrite(User $user, string $name, array $input = []): mixed
{
    return test()->actingAs($user)->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function coachStatus(User $user): array
{
    return test()->actingAs($user)->postJson('/capabilities/agent_status_show')->assertOk()->json('data');
}

function coachMessage(User $user): mixed
{
    return test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi Coach.']);
}

/** @return array{conversations: int, messages: int, turns: int} */
function coachRows(User $user): array
{
    $ids = Conversation::query()->where('user_id', (string) $user->id)->pluck('id');

    return [
        'conversations' => $ids->count(),
        'messages' => DB::table(TableNames::messages())->whereIn('conversation_id', $ids)->count(),
        'turns' => DB::table(TableNames::turns())->whereIn('conversation_id', $ids)->count(),
    ];
}

it('AGT-024 returns no conversation for a new user, theirs after the first message, and none after clearing', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    coachMessage($other)->assertCreated();

    $status = coachStatus($user);
    expect($status['conversation_ulid'])->toBeNull()
        ->and($status['on'])->toBeTrue();
    assertMatchesScreenFixture('agent_status_show', $status);

    $sent = coachMessage($user)->assertCreated();
    $ulid = Conversation::query()->where('user_id', (string) $user->id)->value('ulid');

    expect(coachStatus($user)['conversation_ulid'])->toBe($ulid)
        ->and($sent->json('conversation_ulid'))->toBe($ulid);

    // The client sends it back with the next message, so the user keeps one conversation.
    test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Again.', 'conversation_ulid' => $ulid])->assertCreated();
    expect(coachRows($user))->toBe(['conversations' => 1, 'messages' => 4, 'turns' => 2]);

    $cleared = coachWrite($user, 'coach_conversation_clear')->assertOk();

    expect($cleared->json('data.toast'))->toBe('Conversation cleared.')
        ->and($cleared->json('data.undo'))->toBeNull()
        ->and($cleared->json('data.invalidates'))->toContain('agent_status_show{}')
        ->and(coachStatus($user)['conversation_ulid'])->toBeNull()
        ->and(coachRows($user))->toBe(['conversations' => 0, 'messages' => 0, 'turns' => 0])
        ->and(coachRows($other)['conversations'])->toBe(1);
});

it('AGT-024 returns the user\'s newest conversation', function () {
    $user = User::factory()->create();
    $store = app(ConversationStore::class);
    $store->createConversation((string) Str::ulid(), null, (string) $user->id);
    $newest = $store->createConversation((string) Str::ulid(), null, (string) $user->id);

    expect(coachStatus($user)['conversation_ulid'])->toBe($newest->ulid);
});

it('AGT-020 AGT-024 refuses to clear while one of the user\'s turns is <status>, and deletes nothing', function (string $status) {
    Queue::fake();
    $user = User::factory()->create();
    coachMessage($user)->assertCreated();
    DB::table(TableNames::turns())->update(['status' => $status]);
    $before = coachRows($user);

    coachWrite($user, 'coach_conversation_clear')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.message', 'The Coach is still answering. Try again when it has finished.');

    expect(coachRows($user))->toBe($before)
        ->and($before['conversations'])->toBe(1);
})->with([Turn::STATUS_QUEUED, Turn::STATUS_RUNNING]);

it('AGT-020 AGT-024 clears when the user\'s only turn is completed', function () {
    $user = User::factory()->create();
    coachMessage($user)->assertCreated();
    expect(Turn::query()->pluck('status')->all())->toBe([Turn::STATUS_COMPLETED]);

    coachWrite($user, 'coach_conversation_clear')->assertOk();

    expect(coachRows($user))->toBe(['conversations' => 0, 'messages' => 0, 'turns' => 0]);
});

it('AGT-020 turns the user\'s own Coach off and on', function () {
    $user = User::factory()->create();

    $off = coachWrite($user, 'coach_enabled_set', ['on' => false])->assertOk();
    expect($off->json('data.toast'))->toBe('Coach off.')
        ->and($off->json('data.invalidates'))->toContain('agent_status_show{}')
        ->and(coachStatus($user))->toMatchArray(['on' => false, 'enabled' => true]);

    coachMessage($user)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Your Coach is off. Turn it on in Settings → Coach.');
    expect(coachRows($user)['conversations'])->toBe(0);

    $on = coachWrite($user, 'coach_enabled_set', ['on' => true])->assertOk();
    expect($on->json('data.toast'))->toBe('Coach on.')
        ->and(coachStatus($user)['on'])->toBeTrue();

    coachMessage($user)->assertCreated();
});
