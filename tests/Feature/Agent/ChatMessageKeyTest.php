<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    // TurnLinkingStore still links the keyed message to its turn (AGT-021).
    $meta = json_decode((string) DB::table(TableNames::messages())->where('ulid', $first->json('message_ulid'))->value('meta'), true);
    expect($meta['turn_ulid'])->toBe($first->json('turn_ulid'));

    $changed = test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Something else.'] + $body)
        ->assertUnprocessable();

    expect($changed->json('error.code'))->toBe('validation_failed')
        ->and(collect($changed->json('error.violations'))->pluck('field')->all())->toBe(['client_message_id'])
        ->and(keyedRows($user))->toBe(['messages' => 1, 'turns' => 1]);
});
