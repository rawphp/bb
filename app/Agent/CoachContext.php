<?php

namespace App\Agent;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * What the model sees on a turn (AGT-011, AGT-024): the instructions, which are the same
 * every turn and form the cached prefix; the conversation before the message the turn
 * answers; then the turn's context; then that message. Only the context changes between turns.
 */
final class CoachContext implements ConversationContextProvider
{
    public function __construct(
        private readonly TurnMessages $turnMessages,
        private readonly CoachInstructions $instructions,
        private readonly TurnContext $context,
    ) {}

    public function messagesForTurn(string $conversationUlid, string $turnUlid): array
    {
        $turn = $this->turnMessages->forTurn($turnUlid);
        if ($turn === null) {
            throw (new ModelNotFoundException)->setModel(Turn::class, [$turnUlid]);
        }

        $messages = [['role' => 'system', 'content' => $this->instructions->text()], ...$turn['before']];

        $user = User::query()->find($turn['conversation']->getAttribute('user_id'));
        if ($user instanceof User) {
            $messages[] = ['role' => 'user', 'content' => $this->context->for($conversationUlid, $turnUlid, $user)];
        }
        if ($turn['message'] !== null) {
            $messages[] = ['role' => 'user', 'content' => $turn['message']];
        }

        return $messages;
    }
}
