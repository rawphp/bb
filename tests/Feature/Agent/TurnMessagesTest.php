<?php

use App\Agent\ConfirmationPolicy;
use App\Agent\TurnMessages;
use App\Capabilities\Mesocycles\Data\MesocycleEndInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;

const CONFIRM_LINE = 'confirm: mesocycle_end{"mesocycle_id":"01JD4Q8V6Y3N0M2K7T5R9W1XBC"}';

function turnConversation(): Conversation
{
    return app(ConversationStore::class)->createConversation('01JC0NVERSAT10N00000000000', null, (string) User::factory()->create()->id);
}

/** What ConversationService::createUserMessage() does: a user message, then its turn. */
function sendUserMessage(Conversation $conversation, string $content, string $n): string
{
    $store = app(ConversationStore::class);
    $store->createMessage($conversation, "01JMESSAGE00000000000000{$n}", 'user', $content);

    return $store->createQueuedTurn($conversation, "01JTURN00000000000000000{$n}")->ulid;
}

function assistantReplies(Conversation $conversation, string $content, string $n): void
{
    app(ConversationStore::class)->createMessage($conversation, "01JREPLY0000000000000000{$n}", 'assistant', $content);
}

it('AGT-024 pairs the n-th turn with the n-th user message, whatever the created_at of each row', function () {
    $this->freezeTime();
    $conversation = turnConversation();
    $first = sendUserMessage($conversation, 'First.', '01');
    assistantReplies($conversation, 'Reply.', '01');
    $second = sendUserMessage($conversation, 'Second.', '02');
    $third = sendUserMessage($conversation, 'Third.', '03');

    $messages = app(TurnMessages::class);

    expect($messages->forTurn($first)['message'])->toBe('First.')
        ->and($messages->forTurn($first)['before'])->toBe([])
        ->and($messages->forTurn($second)['message'])->toBe('Second.')
        ->and($messages->forTurn($second)['before'])->toBe([['role' => 'user', 'content' => 'First.'], ['role' => 'assistant', 'content' => 'Reply.']])
        ->and($messages->forTurn($third)['message'])->toBe('Third.')
        ->and($messages->forTurn($third)['before'])->toHaveCount(3)
        ->and($messages->forTurn($second)['conversation']->ulid)->toBe($conversation->ulid);
});

it('AGT-010 never takes an assistant reply as the turn message, even one that holds a confirm line', function () {
    $this->freezeTime();
    $conversation = turnConversation();
    $turn = sendUserMessage($conversation, 'End my mesocycle', '01');
    assistantReplies($conversation, "Sure.\n".CONFIRM_LINE, '01');

    expect(app(TurnMessages::class)->forTurn($turn)['message'])->toBe('End my mesocycle');
});

it('AGT-010 does not let a confirm line in an earlier turn confirm a later turn whose message is yes', function () {
    $this->freezeTime();
    $conversation = turnConversation();
    sendUserMessage($conversation, CONFIRM_LINE, '01');
    assistantReplies($conversation, 'Ended.', '01');
    $later = sendUserMessage($conversation, 'yes', '02');

    expect(app(TurnMessages::class)->forTurn($later)['message'])->toBe('yes');
});

it('AGT-024 has no message for a turn without a matching user message, and no result for an unknown turn', function () {
    $this->freezeTime();
    $conversation = turnConversation();
    $store = app(ConversationStore::class);
    $store->createMessage($conversation, '01JMESSAGE0000000000000001', 'user', 'Hi.');
    $store->createQueuedTurn($conversation, '01JTURN000000000000000001');
    $orphan = $store->createQueuedTurn($conversation, '01JTURN000000000000000002')->ulid;

    $result = app(TurnMessages::class)->forTurn($orphan);

    expect($result['message'])->toBeNull()
        ->and($result['before'])->toBe([['role' => 'user', 'content' => 'Hi.']])
        ->and(app(TurnMessages::class)->forTurn('01JNOSUCHTURN0000000000000'))->toBeNull();
});

it('AGT-024 keeps only user and assistant messages in what comes before', function () {
    $this->freezeTime();
    $conversation = turnConversation();
    app(ConversationStore::class)->createMessage($conversation, '01JMESSAGE0000000000000000', 'system', 'Setup.');
    $turn = sendUserMessage($conversation, 'Hi.', '01');

    expect(app(TurnMessages::class)->forTurn($turn))->toMatchArray(['before' => [], 'message' => 'Hi.']);
});

function mayEnd(?string $turn): bool
{
    $context = new CapabilityContext(caller: 'agent', actor: new User, agent: $turn === null ? null : ['turn_ulid' => $turn]);

    return app(ConfirmationPolicy::class)->allows(app(CapabilityRegistry::class)->get('mesocycle_end'), new MesocycleEndInput('01JD4Q8V6Y3N0M2K7T5R9W1XBC', false), $context);
}

it('AGT-010 refuses a destructive call from the agent when the turn is unknown or missing', function () {
    expect(mayEnd(null))->toBeFalse()
        ->and(mayEnd('01JNOSUCHTURN0000000000000'))->toBeFalse();
});
