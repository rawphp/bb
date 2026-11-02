<?php

namespace App\Agent;

use App\Models\User;
use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;

/**
 * What the model sees on a turn (AGT-011, AGT-024): the instructions, which are the same
 * every turn and form the cached prefix; the conversation up to the message the turn
 * answers; then the turn's context; then that message. Only the context changes between turns.
 */
final class CoachContext implements ConversationContextProvider
{
    public function __construct(
        private readonly ConversationStore $store,
        private readonly TurnMessages $turnMessages,
        private readonly CoachInstructions $instructions,
        private readonly TurnContext $context,
    ) {}

    public function messagesForTurn(string $conversationUlid, string $turnUlid): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->store->turn($turnUlid)->conversation;

        $history = $this->turnMessages->history($turnUlid);
        $answered = $this->turnMessages->userMessage($turnUlid);
        if ($answered !== null) {
            array_pop($history);
        }

        $messages = [['role' => 'system', 'content' => $this->instructions->text()], ...$history];

        $user = User::query()->find($conversation->getAttribute('user_id'));
        if ($user instanceof User) {
            $messages[] = ['role' => 'user', 'content' => $this->context->for($conversationUlid, $turnUlid, $user)];
        }
        if ($answered !== null) {
            $messages[] = ['role' => 'user', 'content' => $answered];
        }

        return $messages;
    }
}
