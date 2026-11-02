<?php

use App\Capabilities\Support\Channel;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

function tokenWith(array $abilities): PersonalAccessToken
{
    return new PersonalAccessToken(['abilities' => $abilities]);
}

it('CAP-022 names the channel from the server-set caller', function (string $caller, Channel $channel) {
    expect(Channel::derive($caller, null))->toBe($channel);
})->with([
    'the PWA' => ['http', Channel::Ui],
    'the in-app agent as a job' => ['job', Channel::Agent],
    'the agent adapter' => ['agent', Channel::Agent],
    'product MCP' => ['mcp', Channel::Mcp],
    'the product CLI' => ['cli', Channel::Cli],
    'an artisan command' => ['artisan', Channel::Cli],
]);

it('CAP-022 counts an http call with the session as the app', function () {
    expect(Channel::derive('http', new TransientToken))->toBe(Channel::Ui);
});

it('CAP-022 counts an http call with a Health bridge token as the bridge', function () {
    expect(Channel::derive('http', tokenWith(['weigh_in_log', 'steps_log'])))->toBe(Channel::Bridge)
        ->and(Channel::derive('http', tokenWith(['steps_log'])))->toBe(Channel::Bridge);
});

it('CAP-022 counts an http call with any other personal access token as the CLI', function () {
    expect(Channel::derive('http', tokenWith(['*'])))->toBe(Channel::Cli)
        ->and(Channel::derive('http', tokenWith(['weigh_in_log', 'meal_check_in'])))->toBe(Channel::Cli)
        ->and(Channel::derive('http', tokenWith([])))->toBe(Channel::Cli);
});

it('CAP-022 labels each channel in plain words', function () {
    expect(array_map(fn (Channel $c): string => $c->label(), Channel::cases()))
        ->toBe(['App', 'Coach', 'MCP', 'CLI', 'Health bridge']);
});
