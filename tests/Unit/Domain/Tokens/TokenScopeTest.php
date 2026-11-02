<?php

use App\Domain\Tokens\TokenScope;

it('CAP-023 gives an Everything token every capability', function () {
    expect(TokenScope::Everything->abilities(['today_show'], []))->toBe(['*']);
});

it('CAP-021 gives a Read only token every read and no write', function () {
    expect(TokenScope::ReadOnly->abilities(['today_show', 'account_show', 'today_show'], ['weigh_in_log']))
        ->toBe(['account_show', 'today_show']);
});

it('INT-001 limits a Health bridge token to weigh_in_log and steps_log', function () {
    expect(TokenScope::HealthBridge->abilities(['today_show'], ['today_show']))->toBe(['weigh_in_log', 'steps_log']);
});

it('CAP-021 gives a custom token the capabilities picked', function () {
    expect(TokenScope::Custom->abilities([], ['weigh_in_log', 'today_show', 'today_show']))
        ->toBe(['today_show', 'weigh_in_log']);
});

it('CAP-021 names each scope in plain words', function (TokenScope $scope, string $label) {
    expect($scope->label())->toBe($label)
        ->and($scope->description())->not->toBe('');
})->with([
    [TokenScope::Everything, 'Everything'],
    [TokenScope::ReadOnly, 'Read only'],
    [TokenScope::HealthBridge, 'Health bridge'],
    [TokenScope::Custom, 'Custom'],
]);
