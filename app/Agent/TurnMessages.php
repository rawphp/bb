<?php

namespace App\Agent;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * Which message a turn answers. Turns do not link to their message, and timestamps have
 * second precision, so the pairing is by order: the conversation's n-th turn (by turn id)
 * answers its n-th user message (by message id). That holds because the package's
 * ConversationService::createUserMessage() is the only place either is created, and it
 * creates one user message and then one turn, in that order. Two sends at the same moment
 * may swap pairs; both are the same user's own messages.
 */
final class TurnMessages
{
    public function __construct(private readonly ConversationStore $store) {}

    /**
     * The turn's conversation, the user and assistant messages before the one the turn
     * answers (in the store's order), and that message. The message is null when the turn
     * has no n-th user message; `before` is then every user and assistant message.
     * Null for an unknown turn.
     *
     * @return array{conversation: Conversation, before: list<array{role: string, content: string}>, message: ?string}|null
     */
    public function forTurn(string $turnUlid): ?array
    {
        try {
            $turn = $this->store->turn($turnUlid);
        } catch (ModelNotFoundException) {
            return null;
        }

        /** @var Conversation $conversation */
        $conversation = $turn->conversation;
        $ordinal = Turn::query()
            ->where('conversation_id', $turn->getAttribute('conversation_id'))
            ->where('id', '<', $turn->getKey())
            ->count();

        $before = [];
        $users = 0;
        $message = null;
        foreach ($this->store->messages($conversation) as $row) {
            $role = $row->getAttribute('role');
            $content = (string) $row->getAttribute('content');
            if ($role === 'user' && $users++ === $ordinal) {
                $message = $content;
                break;
            }
            if (in_array($role, ['user', 'assistant'], true)) {
                $before[] = ['role' => $role, 'content' => $content];
            }
        }

        return ['conversation' => $conversation, 'before' => $before, 'message' => $message];
    }
}
