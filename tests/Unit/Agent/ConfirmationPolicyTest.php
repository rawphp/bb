<?php

use App\Agent\ConfirmationPolicy;
use App\Capabilities\Mesocycles\Data\MesocycleCopyInput;
use App\Capabilities\Mesocycles\Data\MesocycleEndInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;

const ENDED = '01JD4Q8V6Y3N0M2K7T5R9W1XBC';

function endCall(bool $dietBreak = false): MesocycleEndInput
{
    return new MesocycleEndInput(ENDED, $dietBreak);
}

/** Whether the message, as the turn's whole user message, confirms the call. */
function messageConfirms(string $capability, mixed $input, ?string $message): bool
{
    return app(ConfirmationPolicy::class)->confirms(app(CapabilityRegistry::class)->get($capability), $input, $message);
}

it('AGT-010 confirms a call when the whole message is its confirm line, however spaced or ordered', function (string $line) {
    expect(messageConfirms('mesocycle_end', endCall(true), $line))->toBeTrue();
})->with([
    'exact' => ['confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'","diet_break":true}'],
    'keys reordered' => ['confirm: mesocycle_end{"diet_break":true,"mesocycle_id":"'.ENDED.'"}'],
    'spaces' => ['   confirm:mesocycle_end{ "mesocycle_id": "'.ENDED.'", "diet_break": true }   '],
    'surrounding newlines' => ["\nconfirm: mesocycle_end{\"mesocycle_id\":\"".ENDED."\",\"diet_break\":true}\n"],
]);

it('AGT-010 confirms a call when the line leaves out a default the call carries', function () {
    expect(messageConfirms('mesocycle_end', endCall(false), 'confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}'))->toBeTrue();
});

it('AGT-010 refuses a message that holds the line and anything else', function (string $message) {
    expect(messageConfirms('mesocycle_end', endCall(), $message))->toBeFalse();
})->with([
    'text before' => ['Yes, do it.'."\n".'confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}'],
    'text after' => ['confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}'."\nThanks"],
    'same line' => ['please confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"} now'],
    'a pasted reply' => ["Ending it loses the plan.\nconfirm: mesocycle_end{\"mesocycle_id\":\"".ENDED.'"}'],
    'line split after the colon' => ["confirm:\nmesocycle_end{\"mesocycle_id\":\"".ENDED.'"}'],
    'line split inside the json' => ['confirm: mesocycle_end{"mesocycle_id":'."\n\"".ENDED.'"}'],
    'line split by a lone carriage return' => ['confirm: mesocycle_end{"mesocycle_id":'."\r\"".ENDED.'"}'],
    'two lines' => ['confirm: mesocycle_end{"mesocycle_id":"'.ENDED."\"}\nconfirm: mesocycle_end{\"mesocycle_id\":\"".ENDED.'"}'],
]);

it('AGT-010 refuses a plain yes, no message, and a line for another capability or another input', function () {
    $line = 'confirm: mesocycle_end{"mesocycle_id":"'.ENDED.'"}';

    expect(messageConfirms('mesocycle_end', endCall(), 'yes'))->toBeFalse()
        ->and(messageConfirms('mesocycle_end', endCall(), null))->toBeFalse()
        ->and(messageConfirms('mesocycle_end', endCall(), str_replace('mesocycle_end', 'mesocycle_copy', $line)))->toBeFalse()
        ->and(messageConfirms('mesocycle_end', endCall(), str_replace(ENDED, '01JD4Q8V6Y3N0M2K7T5R9W1XZZ', $line)))->toBeFalse()
        ->and(messageConfirms('mesocycle_end', endCall(true), $line))->toBeFalse();
});

it('AGT-010 refuses a line that is malformed, without an exception', function (string $line) {
    expect(messageConfirms('mesocycle_end', endCall(), $line))->toBeFalse();
})->with([
    'broken json' => ['confirm: mesocycle_end{"mesocycle_id":'],
    'json that is no input' => ['confirm: mesocycle_end{"nope":1}'],
]);

it('AGT-010 confirms another destructive capability the same way, by name and input', function () {
    $copy = '{"mesocycle_id":"'.ENDED.'","source_mesocycle_id":"01JD4Q8V6Y3N0M2K7T5R9W1XBD"}';
    $input = MesocycleCopyInput::fromArray(json_decode($copy, true));

    expect(messageConfirms('mesocycle_copy', $input, 'confirm: mesocycle_copy'.$copy))->toBeTrue()
        ->and(messageConfirms('mesocycle_copy', $input, 'confirm: mesocycle_end'.$copy))->toBeFalse();
});

/** @param  array<string, mixed>|MesocycleEndInput  $input */
function agentMayCall(string $capability, mixed $input, string $caller, ?array $agent = null): bool
{
    return app(ConfirmationPolicy::class)->allows(app(CapabilityRegistry::class)->get($capability), $input, new CapabilityContext(caller: $caller, actor: new User, agent: $agent));
}

it('AGT-010 allows a call that is not destructive, and one from another caller, with no line', function () {
    expect(agentMayCall('mesocycle_length_set', [], 'agent'))->toBeTrue()
        ->and(agentMayCall('mesocycle_end', endCall(), 'http'))->toBeTrue()
        ->and(agentMayCall('mesocycle_end', endCall(), 'mcp'))->toBeTrue();
});
