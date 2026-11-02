<?php

use Tests\Support\DomainArch;

arch('app/Domain/WeekBuilder is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\WeekBuilder')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/WeekBuilder builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('WeekBuilder'))->toBe([]);
});
