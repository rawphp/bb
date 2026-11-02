<?php

use Tests\Support\DomainArch;

arch('app/Domain/DayBalance is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\DayBalance')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/DayBalance builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('DayBalance'))->toBe([]);
});
