<?php

namespace App\Agent;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;

/**
 * Which message a turn answers: the user message whose `meta.turn_ulid` is the turn's ulid,
 * written by {@see TurnLinkingStore} when the turn is created. The link replaces inference
 * (by timestamp, or by counting turns against messages) because the package stores the user
 * message and then the turn in two inserts with no transaction. When the second fails, the
 * message is left without a turn, and any rule that counted would answer every later turn
 * with the message before its own, so a stale confirm line could authorise a destructive
 * call. An orphan message stays in the history as background but is never a turn's message.
 */
final class TurnMessages
{
    public function __construct(private readonly ConversationStore $store) {}

    /**
     * The turn's conversation, the user and assistant messages before the one the turn
     * answers (in the store's order), and that message. The message is null when no user
     * message links to the turn; `before` is then every user and assistant message.
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

        $before = [];
        $message = null;
        foreach ($this->store->messages($conversation) as $row) {
            $role = $row->getAttribute('role');
            $content = (string) $row->getAttribute('content');
            if ($role === 'user' && ($row->getAttribute('meta')['turn_ulid'] ?? null) === $turnUlid) {
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
