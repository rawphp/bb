<?php

use Tests\Support\DomainArch;

arch('app/Domain/Macros is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Macros')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Macros builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Macros'))->toBe([]);
});
