<?php

use App\Domain\Defaults\TBL030ActivityFactor;

it('TBL-030 activity factor from planned steps', function (int $index, int $min, ?int $max, float $factor) {
    $row = TBL030ActivityFactor::rows()[$index];

    expect([$row->minSteps, $row->maxSteps, $row->factor])->toBe([$min, $max, $factor]);
})->with([
    'under 5,000' => [0, 0, 4_999, 1.20],
    '5,000 to 7,999' => [1, 5_000, 7_999, 1.30],
    '8,000 to 9,999' => [2, 8_000, 9_999, 1.375],
    '10,000 to 12,999' => [3, 10_000, 12_999, 1.45],
    '13,000 or more' => [4, 13_000, null, 1.55],
]);

it('TBL-030 has five rows', function () {
    expect(TBL030ActivityFactor::rows())->toHaveCount(5);
});
