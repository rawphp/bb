<?php

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\RepTable;
use App\Domain\Progression\RepTableRow;

// CAP-016: every weight a set's load steps allow within 15% of its planned weight, with the PROG-024 reps at each,
// so the view looks a typed weight up instead of doing the maths (OFF-020, OFF-022).

function repTableRules(LoadType $type = LoadType::BarbellOrSmithMachine, WeightUnit $unit = WeightUnit::Kg, ?float $step = null): LoadRules
{
    return new LoadRules($type, $unit, false, $step, new ClosedRange(5, 30));
}

/** @return array<string, int> reps by weight */
function repTableMap(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row->weight] = $row->reps;
    }

    return $map;
}

it('PROG-024 the table holds the equal-effort reps of each weight: 140 x 26 at 2 RIR gives 24 at 145 and 28 at 135', function () {
    $rows = RepTable::rows(140.0, 26, 2, repTableRules(unit: WeightUnit::Lb, step: 5.0), isFatLoss: false);
    $map = repTableMap($rows);

    expect($map['145'])->toBe(24)
        ->and($map['135'])->toBe(28)
        ->and($map['140'])->toBe(26);
});

it('PROG-024 WO-005 the table runs every load step within 15% of the planned weight, lightest first', function () {
    // 82.5 kg x 10 at 2 RIR: 15% either side is 70.1 to 94.9 kg, so 72.5 to 92.5 on a 2.5 kg step.
    $rows = RepTable::rows(82.5, 10, 2, repTableRules(), isFatLoss: false);

    expect(array_map(fn (RepTableRow $row) => $row->weight, $rows))->toBe([72.5, 75.0, 77.5, 80.0, 82.5, 85.0, 87.5, 90.0, 92.5])
        ->and(array_map(fn (RepTableRow $row) => $row->reps, $rows))->toBe([15, 14, 12, 11, 10, 8, 7, 6, 5]);
});

it('PROG-023 PROG-024 fat loss steps the table by the smallest increment the equipment allows', function () {
    $rows = RepTable::rows(80.0, 10, 2, repTableRules(), isFatLoss: true);

    expect(array_slice(array_map(fn (RepTableRow $row) => $row->weight, $rows), 0, 3))->toBe([68.75, 70.0, 71.25]);
});

it('PROG-024 a planned weight off the load steps is still a row of its own', function () {
    $rows = RepTable::rows(81.0, 10, 2, repTableRules(), isFatLoss: false);

    expect(repTableMap($rows))->toHaveKey('81')
        ->and(repTableMap($rows)['81'])->toBe(10);
});

it('PROG-025 WO-006 a set with no planned weight, no planned reps, no RIR or no weight to step has no table', function () {
    expect(RepTable::rows(null, 10, 2, repTableRules(), false))->toBe([])
        ->and(RepTable::rows(80.0, null, 2, repTableRules(), false))->toBe([])
        ->and(RepTable::rows(80.0, 10, null, repTableRules(), false))->toBe([])
        ->and(RepTable::rows(80.0, 10, 2, repTableRules(LoadType::Bodyweight), false))->toBe([]);
});
