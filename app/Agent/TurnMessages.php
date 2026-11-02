<?php

namespace App\Agent;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\Message;

/**
 * Which message a turn answers. Turns do not link to their message, so one rule serves
 * the context and the confirmation check alike: a turn answers the latest user message
 * created at or before the turn. Later messages belong to later turns.
 */
final class TurnMessages
{
    public function __construct(private readonly ConversationStore $store) {}

    /**
     * The user and assistant messages up to and including the one the turn answers.
     *
     * @return list<array{role: string, content: string}>
     */
    public function history(string $turnUlid): array
    {
        [$messages, $answered] = $this->answered($turnUlid);

        $history = [];
        foreach (array_slice($messages, 0, $answered === null ? 0 : $answered + 1) as $message) {
            $role = $message->getAttribute('role');
            if (in_array($role, ['user', 'assistant'], true)) {
                $history[] = ['role' => $role, 'content' => (string) $message->getAttribute('content')];
            }
        }

        return $history;
    }

    /** The message the turn answers; null when there is none or the turn is unknown. */
    public function userMessage(string $turnUlid): ?string
    {
        [$messages, $answered] = $this->answered($turnUlid);

        return $answered === null ? null : (string) $messages[$answered]->getAttribute('content');
    }

    /**
     * @return array{list<Message>, int|null} the conversation's messages, and the index of the one the turn answers
     */
    private function answered(string $turnUlid): array
    {
        try {
            $turn = $this->store->turn($turnUlid);
        } catch (ModelNotFoundException) {
            return [[], null];
        }

        /** @var Conversation $conversation */
        $conversation = $turn->conversation;
        $messages = $this->store->messages($conversation);
        $cutoff = $turn->getAttribute('created_at');

        $answered = null;
        foreach ($messages as $i => $message) {
            $createdAt = $message->getAttribute('created_at');
            if ($message->getAttribute('role') === 'user' && ($cutoff === null || $createdAt === null || $createdAt <= $cutoff)) {
                $answered = $i;
            }
        }

        return [$messages, $answered];
    }
}
