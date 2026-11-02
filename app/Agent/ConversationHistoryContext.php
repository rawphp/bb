<?php

namespace App\Agent;

use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;

/**
 * The turn's messages: the conversation so far, user and assistant.
 * COACH_CTX (#61) adds the instructions and per-turn context (AGT-011).
 */
final class ConversationHistoryContext implements ConversationContextProvider
{
    public function __construct(private readonly ConversationStore $store) {}

    public function messagesForTurn(string $conversationUlid, string $turnUlid): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->store->turn($turnUlid)->conversation;

        $messages = [];
        foreach ($this->store->messages($conversation) as $message) {
            // The package model declares no properties, so read its attributes by name.
            $role = $message->getAttribute('role');
            if (in_array($role, ['user', 'assistant'], true)) {
                $messages[] = ['role' => $role, 'content' => (string) $message->getAttribute('content')];
            }
        }

        return $messages;
    }
}
