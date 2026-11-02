<?php

use Tests\Support\DomainArch;

arch('app/Domain/Phases is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Phases')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Phases builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Phases'))->toBe([]);
});
