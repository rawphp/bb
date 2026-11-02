<?php

use App\Domain\Tokens\TokenScope;

it('CAP-023 gives an Everything token every capability', function () {
    expect(TokenScope::Everything->abilities(['today_show'], [], ['today_show', 'weigh_in_log']))->toBe(['*']);
});

it('CAP-021 gives a Read only token every read and no write', function () {
    expect(TokenScope::ReadOnly->abilities(['today_show', 'account_show', 'today_show'], ['weigh_in_log'], ['today_show', 'account_show', 'weigh_in_log']))
        ->toBe(['account_show', 'today_show']);
});

it('INT-001 limits a Health bridge token to weigh_in_log and steps_log', function () {
    expect(TokenScope::HealthBridge->abilities(['today_show'], ['today_show'], ['today_show']))->toBe(['weigh_in_log', 'steps_log']);
});

it('CAP-021 gives a custom token the capabilities picked', function () {
    expect(TokenScope::Custom->abilities([], ['weigh_in_log', 'today_show', 'today_show'], ['today_show', 'weigh_in_log']))
        ->toBe(['today_show', 'weigh_in_log']);
});

it('CAP-021 refuses a custom token with nothing picked', function () {
    TokenScope::Custom->abilities([], [], ['today_show']);
})->throws(InvalidArgumentException::class, 'Pick at least one action for a custom token.');

it('CAP-021 refuses a custom token with a capability a token cannot call', function () {
    TokenScope::Custom->abilities([], ['today_show', 'token_create'], ['today_show']);
})->throws(InvalidArgumentException::class, 'A token can\'t use token_create.');

it('CAP-021 names each scope in plain words', function (TokenScope $scope, string $label) {
    expect($scope->label())->toBe($label)
        ->and($scope->description())->not->toBe('');
})->with([
    [TokenScope::Everything, 'Everything'],
    [TokenScope::ReadOnly, 'Read only'],
    [TokenScope::HealthBridge, 'Health bridge'],
    [TokenScope::Custom, 'Custom'],
]);
