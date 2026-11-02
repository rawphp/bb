<?php

use Tests\Support\DomainArch;

arch('app/Domain/MealSplit is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\MealSplit')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/MealSplit builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('MealSplit'))->toBe([]);
});
