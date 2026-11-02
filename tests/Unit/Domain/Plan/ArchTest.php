<?php

use Tests\Support\DomainArch;

arch('app/Domain/Plan is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Plan')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Plan builds dates only in PlanWeekGrid, from whole dates it is given', function () {
    // PlanWeekGrid rebuilds its bounds from their Y-m-d text on the UTC calendar, so no clock fills anything in.
    expect(DomainArch::filesThatBuildDates('Plan'))->toBe(['PlanWeekGrid.php']);
});
