<?php

use App\Domain\Defaults\TBL050OnTargetBand;

it('TBL-050 fat loss, mini-cut and muscle gain band', function () {
    expect(TBL050OnTargetBand::ABSOLUTE_PERCENT_BODYWEIGHT_PER_WEEK)->toBe(0.15)
        ->and(TBL050OnTargetBand::RELATIVE_PERCENT_OF_TARGET_RATE)->toBe(25.0);
});

it('TBL-050 maintenance corridor', function () {
    expect(TBL050OnTargetBand::MAINTENANCE_CORRIDOR_PERCENT_OF_START_TREND_WEIGHT)->toBe(1.0);
});
