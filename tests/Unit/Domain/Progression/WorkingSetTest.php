<?php

use App\Domain\Progression\WorkingSet;

it('WO-025 a logged set counts and a skipped set never does', function () {
    expect(WorkingSet::counts(isLogged: true, isSkipped: false))->toBeTrue()
        ->and(WorkingSet::counts(isLogged: true, isSkipped: true))->toBeFalse()
        ->and(WorkingSet::counts(isLogged: false, isSkipped: false))->toBeFalse()
        ->and(WorkingSet::counts(isLogged: false, isSkipped: true))->toBeFalse();
});
