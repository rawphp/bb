<?php

use App\Agent\ConfirmationPolicy;
use App\Agent\TurnMessages;
use App\Capabilities\Mesocycles\Data\MesocycleCopyInput;
use App\Capabilities\Mesocycles\Data\MesocycleEndInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Tests\Support\TurnStoreFake;

const ENDED = '01JD4Q8V6Y3N0M2K7T5R9W1XBC';

/** @param  array<string, mixed>|MesocycleEndInput  $input */
function agentMayCall(string $capability, mixed $input, ?string $userMessage, string $caller = 'agent', ?string $turn = 'TURN'): bool
{
    $messages = $userMessage === null ? [] : [['user', $userMessage, '2026-11-03 08:00:00']];
    $policy = new ConfirmationPolicy(new TurnMessages(TurnStoreFake::with($messages, '2026-11-03 09:00:00')));
    $context = new CapabilityContext(caller: $caller, actor: new User, agent: $turn === null ? null : ['turn_ulid' => $turn]);

    return $policy->allows(app(CapabilityRegistry::class)->get($capability), $input, $context);
}

function endCall(bool $dietBreak = false): MesocycleEndInput
{
    return new MesocycleEndInput(ENDED, $dietBreak);
}

it('AGT-010 refuses a destructive call from the agent with no turn', function () {
    expect(agentMayCall('mesocycle_end', endCall(), 'confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}', turn: null))->toBeFalse();
});

it('AGT-010 refuses a destructive call from the agent when the turn has no user message', function () {
    expect(agentMayCall('mesocycle_end', endCall(), null))->toBeFalse();
});

it('AGT-010 refuses a destructive call after a plain yes', function () {
    expect(agentMayCall('mesocycle_end', endCall(), 'yes'))->toBeFalse();
});

it('AGT-010 refuses a destructive call when the confirm line names another capability', function () {
    expect(agentMayCall('mesocycle_end', endCall(), 'confirm: mesocycle_copy{"mesocycle_id":"'.ENDED.'"}'))->toBeFalse();
});

it('AGT-010 refuses a destructive call when the confirm line holds other input', function () {
    expect(agentMayCall('mesocycle_end', endCall(), 'confirm: mesocycle_end{"mesocycle_id":"01JD4Q8V6Y3N0M2K7T5R9W1XZZ"}'))->toBeFalse()
        ->and(agentMayCall('mesocycle_end', endCall(true), 'confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}'))->toBeFalse();
});

it('AGT-010 allows a destructive call the user confirmed, however the line is spaced or ordered', function (string $line) {
    expect(agentMayCall('mesocycle_end', endCall(true), "Yes, do it.\n".$line."\nThanks"))->toBeTrue();
})->with([
    'exact' => ['confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'","diet_break":true}'],
    'keys reordered' => ['confirm: mesocycle_end{"diet_break":true,"mesocycle_id":"'.ENDED.'"}'],
    'spaces' => ['   confirm:mesocycle_end{ "mesocycle_id": "'.ENDED.'", "diet_break": true }   '],
]);

it('AGT-010 matches a confirm line that leaves out a default the call carries', function () {
    expect(agentMayCall('mesocycle_end', endCall(false), 'confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}'))->toBeTrue();
});

it('AGT-010 allows the call when any one of several confirm lines matches', function () {
    $lines = "confirm: mesocycle_end{\"mesocycle_id\":\"01JD4Q8V6Y3N0M2K7T5R9W1XZZ\"}\nconfirm: mesocycle_end{\"mesocycle_id\":\"".ENDED.'"}';

    expect(agentMayCall('mesocycle_end', endCall(), $lines))->toBeTrue();
});

it('AGT-010 refuses a call on a line that is malformed, without an exception', function (string $line) {
    expect(agentMayCall('mesocycle_end', endCall(), $line))->toBeFalse();
})->with([
    'broken json' => ['confirm: mesocycle_end{"mesocycle_id":'],
    'json that is no input' => ['confirm: mesocycle_end{"nope":1}'],
    'not on its own line' => ['please confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"} now'],
]);

it('AGT-010 confirms another destructive capability the same way, by name and input', function () {
    $copy = '{"mesocycle_id":"'.ENDED.'","source_mesocycle_id":"01JD4Q8V6Y3N0M2K7T5R9W1XBD"}';
    $input = MesocycleCopyInput::fromArray(json_decode($copy, true));

    expect(agentMayCall('mesocycle_copy', $input, 'confirm: mesocycle_copy'.$copy))->toBeTrue()
        ->and(agentMayCall('mesocycle_copy', $input, 'confirm: mesocycle_end'.$copy))->toBeFalse();
});

it('AGT-010 allows a call that is not destructive, and one from another caller, with no line', function () {
    expect(agentMayCall('mesocycle_length_set', [], null))->toBeTrue()
        ->and(agentMayCall('mesocycle_end', endCall(), null, caller: 'http'))->toBeTrue()
        ->and(agentMayCall('mesocycle_end', endCall(), null, caller: 'mcp'))->toBeTrue();
});
