<?php

use App\Agent\CurrentTurn;

it('CAP-022 AGT-013 restores the outer turn after a nested invoke', function () {
    $turn = new CurrentTurn;
    $seen = [];

    $turn->during('OUTER', function () use ($turn, &$seen): void {
        $seen[] = $turn->ulid();
        $turn->during('INNER', function () use ($turn, &$seen): void {
            $seen[] = $turn->ulid();
        });
        $seen[] = $turn->ulid();
        $turn->during(null, function () use ($turn, &$seen): void {
            $seen[] = $turn->ulid();
        });
        $seen[] = $turn->ulid();
    });

    expect($seen)->toBe(['OUTER', 'INNER', 'OUTER', null, 'OUTER'])
        ->and($turn->ulid())->toBeNull();
});

it('CAP-022 AGT-013 is empty after an invoke that throws', function () {
    $turn = new CurrentTurn;

    expect(fn () => $turn->during('TURN', fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and($turn->ulid())->toBeNull();
});

it('CAP-022 AGT-013 returns what the invoke returns', function () {
    expect((new CurrentTurn)->during('TURN', fn (): string => 'done'))->toBe('done');
});
