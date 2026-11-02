<?php

use App\Agent\TurnMessages;
use Tests\Support\TurnStoreFake;

function turnMessages(array $messages, string $turnCreatedAt = '2026-11-03 09:00:00'): TurnMessages
{
    return new TurnMessages(TurnStoreFake::with($messages, $turnCreatedAt));
}

it('AGT-024 answers the latest user message created at or before the turn', function () {
    $messages = turnMessages([
        ['user', 'First.', '2026-11-03 08:00:00'],
        ['assistant', 'Reply.', '2026-11-03 08:00:05'],
        ['user', 'Second.', '2026-11-03 09:00:00'],
    ]);

    expect($messages->userMessage('TURN'))->toBe('Second.');
});

it('AGT-024 leaves a message sent after the turn to the turn that answers it', function () {
    $messages = turnMessages([
        ['user', 'First.', '2026-11-03 08:00:00'],
        ['assistant', 'Reply.', '2026-11-03 08:00:05'],
        ['user', 'Later.', '2026-11-03 09:30:00'],
    ], '2026-11-03 08:00:00');

    expect($messages->userMessage('TURN'))->toBe('First.')
        ->and($messages->history('TURN'))->toBe([['role' => 'user', 'content' => 'First.']]);
});

it('AGT-024 has no user message when none came before the turn, or the turn is unknown', function () {
    $none = turnMessages([['user', 'Later.', '2026-11-03 09:30:00']]);

    expect($none->userMessage('TURN'))->toBeNull()
        ->and($none->history('TURN'))->toBe([])
        ->and($none->userMessage('OTHER'))->toBeNull()
        ->and($none->history('OTHER'))->toBe([]);
});

it('AGT-024 keeps only user and assistant messages in the history', function () {
    $messages = turnMessages([
        ['system', 'Setup.', '2026-11-03 08:00:00'],
        ['user', 'Hi.', '2026-11-03 08:00:01'],
    ]);

    expect($messages->history('TURN'))->toBe([['role' => 'user', 'content' => 'Hi.']]);
});
