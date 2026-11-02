<?php

use App\Agent\MeteredTurnClaim;
use App\Models\User;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Contracts\TurnClaim;
use Rawphp\CapabilitiesAi\Models\Turn;

/** A running turn of a new user's conversation. */
function runningTurn(): Turn
{
    $store = app(ConversationStore::class);
    $conversation = $store->createConversation(strtoupper(bin2hex(random_bytes(13))), null, (string) User::factory()->create()->id);
    $turn = $store->createQueuedTurn($conversation, strtoupper(bin2hex(random_bytes(13))));
    app(TurnClaim::class)->claim($turn->ulid, 'test-worker');

    return $turn;
}

$usage = [
    ['latency_ms' => 900, 'input_tokens' => 1_200, 'output_tokens' => 300],
    ['latency_ms' => 700, 'input_tokens' => 1_500],
    ['latency_ms' => 50],
];

it('AGT-005 is the turn claim the package runs turns with', function () {
    expect(app(TurnClaim::class))->toBeInstanceOf(MeteredTurnClaim::class);
});

it('AGT-005 keeps each turn\'s input and output tokens wherever the package records usage', function (Closure $finish, string $status) {
    $turn = runningTurn();

    $finish(app(TurnClaim::class), $turn->ulid);

    expect($turn->fresh())
        ->status->toBe($status)
        ->getAttribute('tokens')->toBe(3_000);
})->with([
    'completed' => [fn (TurnClaim $claim, string $ulid) => expect($claim->complete($ulid, $usage))->toBeTrue(), 'completed'],
    'failed' => [fn (TurnClaim $claim, string $ulid) => expect($claim->fail($ulid, 'boom', $usage))->toBeTrue(), 'failed'],
    // The runner sees the cancel before its next round and only records the rounds so far.
    'cancelled mid-turn' => [function (TurnClaim $claim, string $ulid) use ($usage) {
        $claim->cancel($ulid);
        $claim->recordUsage($ulid, $usage);
    }, 'cancelled'],
    'cancel won the race to complete' => [function (TurnClaim $claim, string $ulid) use ($usage) {
        $claim->cancel($ulid);
        expect($claim->complete($ulid, $usage))->toBeFalse();
    }, 'cancelled'],
]);
