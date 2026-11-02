<?php

use Tests\Support\DomainArch;

arch('app/Domain/MealFit is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\MealFit')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/MealFit builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('MealFit'))->toBe([]);
});
