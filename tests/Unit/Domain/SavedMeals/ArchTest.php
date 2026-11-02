<?php

use Tests\Support\DomainArch;

arch('app/Domain/SavedMeals is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\SavedMeals')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/SavedMeals builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('SavedMeals'))->toBe([]);
});
