<?php

use App\Domain\Defaults\TBL051ReviewThresholds;

it('TBL-051 expenditure average weights, newest first', function () {
    expect(TBL051ReviewThresholds::EXPENDITURE_WEEK_WEIGHTS_NEWEST_FIRST)->toBe([0.5, 0.3, 0.2]);
});

it('TBL-051 training fatigue', function () {
    expect(TBL051ReviewThresholds::TRAINING_FATIGUE_UNDERPERFORMING_MUSCLES)->toBe(2)
        ->and(TBL051ReviewThresholds::TRAINING_FATIGUE_STILL_SORE_OR_TOO_MUCH_SHARE_ABOVE)->toEqualWithDelta(1.0 / 3.0, 1e-12);
});

it('TBL-051 diet fatigue', function () {
    expect(TBL051ReviewThresholds::DIET_FATIGUE_DEFICIT_WEEKS)->toBe(6)
        ->and(TBL051ReviewThresholds::DIET_FATIGUE_LOGGED_DAYS_DROP)->toBe(2)
        ->and(TBL051ReviewThresholds::DIET_FATIGUE_LOGGED_DAYS_AVERAGE_WEEKS)->toBe(3);
});

it('TBL-051 out of rep range', function () {
    expect(TBL051ReviewThresholds::OUT_OF_REP_RANGE_SESSIONS_RUNNING)->toBe(2);
});
