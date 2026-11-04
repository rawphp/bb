<?php

use App\Domain\Progression\BestSetChange;
use App\Domain\Progression\SessionRecap;
use App\Domain\Progression\SetLoad;

it('WO-011 the best set is the heaviest, then the one with the most reps', function () {
    expect(SessionRecap::best([new SetLoad(80, 10), new SetLoad(82.5, 8), new SetLoad(82.5, 9), new SetLoad(80, 12)]))
        ->toEqual(new SetLoad(82.5, 9))
        ->and(SessionRecap::best([new SetLoad(null, 8), new SetLoad(null, 12)]))->toEqual(new SetLoad(null, 12))
        ->and(SessionRecap::best([]))->toBeNull();
});

it('WO-011 the weight lifted is weight times reps over the sets, and a bodyweight set adds nothing', function () {
    expect(SessionRecap::lifted([new SetLoad(80, 10), new SetLoad(80, 9), new SetLoad(null, 12), new SetLoad(12.5, 2)]))->toBe(1545.0)
        ->and(SessionRecap::lifted([]))->toBe(0.0);
});

it('WO-011 the best set against last week\'s is the weight it moved, else the reps', function () {
    expect(SessionRecap::change(new SetLoad(82.5, 8), new SetLoad(80, 10)))->toEqual(new BestSetChange(2.5, 0))
        ->and(SessionRecap::change(new SetLoad(77.5, 12), new SetLoad(80, 10)))->toEqual(new BestSetChange(-2.5, 0))
        ->and(SessionRecap::change(new SetLoad(80, 12), new SetLoad(80, 10)))->toEqual(new BestSetChange(0.0, 2))
        ->and(SessionRecap::change(new SetLoad(null, 9), new SetLoad(null, 11)))->toEqual(new BestSetChange(0.0, -2))
        ->and(SessionRecap::change(new SetLoad(80, 10), new SetLoad(80, 10)))->toEqual(new BestSetChange(0.0, 0));
});

it('WO-011 a best set is up when it gained weight, or reps at the same weight', function () {
    expect((new BestSetChange(2.5, 0))->isUp())->toBeTrue()
        ->and((new BestSetChange(0.0, 1))->isUp())->toBeTrue()
        ->and((new BestSetChange(-2.5, 0))->isUp())->toBeFalse()
        ->and((new BestSetChange(0.0, -1))->isUp())->toBeFalse()
        ->and((new BestSetChange(0.0, 0))->isUp())->toBeFalse();
});

it('WO-011 a session lasts the whole minutes from its start to its end, never less than none', function () {
    $start = new DateTimeImmutable('2026-09-28 17:30:00');

    expect(SessionRecap::minutes($start, new DateTimeImmutable('2026-09-28 18:18:40')))->toBe(48)
        ->and(SessionRecap::minutes($start, new DateTimeImmutable('2026-09-28 17:29:00')))->toBe(0);
});
