<?php

use App\Domain\Defaults\TBL013MuscleGainBodyFat;

it('TBL-013 counts half of the weight gained as fat', function () {
    expect(TBL013MuscleGainBodyFat::FAT_FRACTION_OF_WEIGHT_GAINED)->toBe(0.5);
});
