<?php

use App\Capabilities\Support\Channel;
use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

function tokenWith(TokenScope $scope, array $abilities): PersonalAccessToken
{
    return new PersonalAccessToken(['scope' => $scope, 'abilities' => $abilities]);
}

it('CAP-022 names the channel from the server-set caller', function (string $caller, Channel $channel) {
    expect(Channel::derive($caller, null))->toBe($channel);
})->with([
    'the PWA' => ['http', Channel::Ui],
    'the in-app agent (AGT-004)' => ['agent', Channel::Agent],
    'product MCP' => ['mcp', Channel::Mcp],
    'the product CLI' => ['cli', Channel::Cli],
]);

it('CAP-022 names no channel for the server itself, a queued job or an artisan command', function (string $caller) {
    expect(Channel::derive($caller, null))->toBeNull();
})->with(['job', 'artisan']);

it('CAP-022 refuses to guess the channel of a caller it does not know', function () {
    Channel::derive('webhook', null);
})->throws(UnexpectedValueException::class, 'webhook');

it('CAP-022 counts an http call with the session as the app', function () {
    expect(Channel::derive('http', new TransientToken))->toBe(Channel::Ui);
});

it('CAP-022 counts an http call with a token made with the Health bridge scope as the bridge', function () {
    expect(Channel::derive('http', tokenWith(TokenScope::HealthBridge, TokenScope::HEALTH_BRIDGE)))->toBe(Channel::Bridge);
});

it('CAP-022 counts an http call with any other personal access token as the CLI, even one picked down to the bridge\'s abilities', function (TokenScope $scope, array $abilities) {
    expect(Channel::derive('http', tokenWith($scope, $abilities)))->toBe(Channel::Cli);
})->with([
    'everything' => [TokenScope::Everything, ['*']],
    'read only' => [TokenScope::ReadOnly, ['activity_show']],
    'custom, the bridge\'s two writes' => [TokenScope::Custom, TokenScope::HEALTH_BRIDGE],
]);

it('CAP-022 labels each channel in plain words', function () {
    expect(array_map(fn (Channel $c): string => $c->label(), Channel::cases()))
        ->toBe(['App', 'Coach', 'MCP', 'CLI', 'Health bridge']);
});
