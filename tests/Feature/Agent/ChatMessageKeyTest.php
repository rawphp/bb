<?php

use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Domain\ConversationService;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\TableNames;
use Tests\Support\RecordingLlmClient;

// A Coach chat message sent again with its client key (CAP-014): the chat routes sit
// outside the capability rail, so the key goes in the body as client_message_id, and the
// package answers a repeat with the first send's ids instead of storing it twice.

beforeEach(fn () => (new RecordingLlmClient([['content' => 'Hello.']]))->install());

/** @return array{messages: int, turns: int} */
function keyedRows(User $user): array
{
    $ids = Conversation::query()->where('user_id', (string) $user->id)->pluck('id');

    return [
        'messages' => DB::table(TableNames::messages())->whereIn('conversation_id', $ids)->count(),
        'turns' => DB::table(TableNames::turns())->whereIn('conversation_id', $ids)->count(),
    ];
}

/** @return array<string, mixed>|null */
function keyedMeta(string $messageUlid): ?array
{
    return json_decode((string) DB::table(TableNames::messages())->where('ulid', $messageUlid)->value('meta'), true);
}

function keyedSend(User $user, string $key, string $content = 'Hi Coach.'): mixed
{
    return test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => $content, 'client_message_id' => $key]);
}

it('CAP-014 stores a chat message sent twice with one key once', function () {
    Queue::fake();
    $user = User::factory()->create();
    $body = ['content' => 'Hi Coach.', 'client_message_id' => 'chat.send:01JX0000000000000000000000'];

    $first = test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', $body)->assertCreated();
    $again = test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', $body)->assertOk();

    expect($again->json('message_ulid'))->toBe($first->json('message_ulid'))
        ->and($again->json('turn_ulid'))->toBe($first->json('turn_ulid'))
        ->and($again->json('conversation_ulid'))->toBe($first->json('conversation_ulid'))
        ->and(keyedRows($user))->toBe(['messages' => 1, 'turns' => 1]);

    // TurnLinkingStore still links the keyed message to its turn (AGT-021), and the key is
    // marked sent once the turn's job was queued.
    expect(keyedMeta($first->json('message_ulid')))->toEqual(['turn_ulid' => $first->json('turn_ulid'), 'sent' => true]);

    $changed = test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Something else.'] + $body)
        ->assertUnprocessable();

    expect($changed->json('error.code'))->toBe('validation_failed')
        ->and(collect($changed->json('error.violations'))->pluck('field')->all())->toBe(['client_message_id'])
        ->and(keyedRows($user))->toBe(['messages' => 1, 'turns' => 1]);
});

it('CAP-014 keeps a client key to its owner: another user sending the same key stores their own message', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $key = 'chat.send:01JX0000000000000000000001';

    $first = keyedSend($owner, $key)->assertCreated();
    $theirs = keyedSend($other, $key)->assertCreated();

    expect($theirs->json('conversation_ulid'))->not->toBe($first->json('conversation_ulid'))
        ->and($theirs->json('message_ulid'))->not->toBe($first->json('message_ulid'))
        ->and($theirs->json('turn_ulid'))->not->toBe($first->json('turn_ulid'))
        ->and(keyedRows($owner))->toBe(['messages' => 1, 'turns' => 1])
        ->and(keyedRows($other))->toBe(['messages' => 1, 'turns' => 1]);
});

it('CAP-014 undoes a keyed send whose turn never reached the queue, so a retry with the key stores it once', function () {
    Queue::fake();
    $user = User::factory()->create();
    $key = 'chat.send:01JX0000000000000000000002';
    $queue = app(Dispatcher::class);
    // The service keeps the dispatcher it was built with, so it is rebuilt around each one.
    app()->forgetInstance(ConversationService::class);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));

    keyedSend($user, $key)->assertServerError();

    expect(Conversation::query()->where('user_id', (string) $user->id)->count())->toBe(0)
        ->and(keyedRows($user))->toBe(['messages' => 0, 'turns' => 0]);

    app()->instance(Dispatcher::class, $queue);
    app()->forgetInstance(ConversationService::class);
    $retry = keyedSend($user, $key)->assertCreated();

    expect(keyedRows($user))->toBe(['messages' => 1, 'turns' => 1])
        ->and(keyedMeta($retry->json('message_ulid')))->toEqual(['turn_ulid' => $retry->json('turn_ulid'), 'sent' => true]);
});

it('CAP-014 answers conflict to a repeat while the first send with the key is still being dispatched', function () {
    Queue::fake();
    $user = User::factory()->create();
    $key = 'chat.send:01JX0000000000000000000003';
    // The first send stored its message and queued turn and has not handed the job over yet.
    $store = app(ConversationStore::class);
    $conversation = $store->createConversation((string) Str::ulid(), null, (string) $user->id);
    $turnUlid = (string) Str::ulid();
    $store->createMessage($conversation, (string) Str::ulid(), 'user', 'Hi Coach.', $key, $turnUlid);
    $store->createQueuedTurn($conversation, $turnUlid);

    $repeat = keyedSend($user, $key)->assertConflict();

    expect($repeat->json('error.code'))->toBe('conflict')
        ->and(keyedRows($user))->toBe(['messages' => 1, 'turns' => 1]);
    Queue::assertNothingPushed();
});
