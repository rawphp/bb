<?php

use Tests\Support\DomainArch;

arch('app/Domain/Defaults is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Defaults')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Defaults builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Defaults'))->toBe([]);
});
