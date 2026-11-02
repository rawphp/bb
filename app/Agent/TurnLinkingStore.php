<?php

namespace App\Agent;

use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\Message;
use Rawphp\CapabilitiesAi\Models\Proposal;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * The package's store, plus a link from each turn to the user message it was created for:
 * the turn's ulid goes into that message's meta as `turn_ulid`. ConversationService stores
 * the user message and then the turn, in one request, so the last user message stored for a
 * conversation is the turn's. A message whose turn never got stored keeps no link.
 */
final class TurnLinkingStore implements ConversationStore
{
    /** @var array<int|string, Message> the user message stored last, by conversation id */
    private array $pending = [];

    public function __construct(private readonly ConversationStore $inner) {}

    public function createMessage(Conversation $conversation, string $ulid, string $role, string $content): Message
    {
        $message = $this->inner->createMessage($conversation, $ulid, $role, $content);
        if ($role === 'user') {
            $this->pending[$conversation->getKey()] = $message;
        }

        return $message;
    }

    public function createQueuedTurn(Conversation $conversation, string $ulid): Turn
    {
        $message = $this->pending[$conversation->getKey()] ?? null;
        unset($this->pending[$conversation->getKey()]);

        $turn = $this->inner->createQueuedTurn($conversation, $ulid);

        if ($message !== null) {
            $message->setAttribute('meta', [...($message->getAttribute('meta') ?? []), 'turn_ulid' => $ulid]);
            $message->save();
        }

        return $turn;
    }

    public function activeTurnCount(): int
    {
        return $this->inner->activeTurnCount();
    }

    public function ownedConversation(string $conversationUlid, ?string $ownerId): Conversation
    {
        return $this->inner->ownedConversation($conversationUlid, $ownerId);
    }

    public function createConversation(string $ulid, ?string $appId, ?string $userId): Conversation
    {
        return $this->inner->createConversation($ulid, $appId, $userId);
    }

    public function messages(Conversation $conversation): array
    {
        return $this->inner->messages($conversation);
    }

    public function proposals(Conversation $conversation): array
    {
        return $this->inner->proposals($conversation);
    }

    public function hasActiveTurns(Conversation $conversation): bool
    {
        return $this->inner->hasActiveTurns($conversation);
    }

    public function close(Conversation $conversation): void
    {
        $this->inner->close($conversation);
    }

    public function turn(string $turnUlid): Turn
    {
        return $this->inner->turn($turnUlid);
    }

    public function ownedTurn(string $turnUlid, string $ownerId): Turn
    {
        return $this->inner->ownedTurn($turnUlid, $ownerId);
    }

    public function createProposal(Turn $turn, string $ulid, string $type, mixed $payload, ?string $targetCapability, ?string $schemaHash): Proposal
    {
        return $this->inner->createProposal($turn, $ulid, $type, $payload, $targetCapability, $schemaHash);
    }

    public function proposal(string $proposalUlid): Proposal
    {
        return $this->inner->proposal($proposalUlid);
    }

    public function proposalOwnedBy(string $proposalUlid, string $ownerId): bool
    {
        return $this->inner->proposalOwnedBy($proposalUlid, $ownerId);
    }

    public function transitionProposal(string $proposalUlid, string $fromStatus, array $attributes): bool
    {
        return $this->inner->transitionProposal($proposalUlid, $fromStatus, $attributes);
    }
}
