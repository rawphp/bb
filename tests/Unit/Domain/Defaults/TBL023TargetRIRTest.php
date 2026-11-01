<?php

use App\Domain\Defaults\TBL023TargetRIR;

it('TBL-023 target RIR by training weeks', function (int $weeks, array $rir) {
    expect(TBL023TargetRIR::RIR_BY_TRAINING_WEEKS[$weeks])->toBe($rir)
        ->and(TBL023TargetRIR::rir($weeks))->toBe($rir);
})->with([
    'resensitization only' => [2, [3, 2]],
    [3, [3, 1, 0]],
    [4, [3, 2, 1, 0]],
    [5, [3, 2, 2, 1, 0]],
    [6, [3, 3, 2, 2, 1, 0]],
    [7, [3, 3, 2, 2, 1, 1, 0]],
]);

it('TBL-023 has exactly the six rows and no row for 8 weeks', function () {
    expect(array_keys(TBL023TargetRIR::RIR_BY_TRAINING_WEEKS))->toBe([2, 3, 4, 5, 6, 7])
        ->and(TBL023TargetRIR::rir(8))->toBeNull();
});
