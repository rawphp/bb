<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Mockery\MockInterface;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\Message;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * A ConversationStore mock that holds one turn and its conversation's messages in memory,
 * so the rules about which message a turn answers run without a database.
 */
final class TurnStoreFake
{
    /**
     * @param  list<array{string, string, string}>  $messages  role, content and created_at, oldest first
     */
    public static function with(array $messages, string $turnCreatedAt, string $turnUlid = 'TURN', ?int $userId = 7): MockInterface
    {
        $conversation = (new Conversation)->forceFill(['id' => 1, 'ulid' => 'CONV', 'user_id' => $userId === null ? null : (string) $userId]);
        $turn = (new Turn)->forceFill(['ulid' => $turnUlid, 'created_at' => $turnCreatedAt]);
        $turn->setRelation('conversation', $conversation);

        $rows = array_map(fn (array $m): Message => (new Message)->forceFill(['role' => $m[0], 'content' => $m[1], 'created_at' => $m[2]]), $messages);

        $store = Mockery::mock(ConversationStore::class);
        $store->shouldReceive('turn')->andReturnUsing(function (string $ulid) use ($turn, $turnUlid): Turn {
            if ($ulid !== $turnUlid) {
                throw (new ModelNotFoundException)->setModel(Turn::class, [$ulid]);
            }

            return $turn;
        });
        $store->shouldReceive('messages')->andReturn($rows);

        return $store;
    }
}
