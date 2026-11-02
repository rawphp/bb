<?php

use Tests\Support\DomainArch;

arch('app/Domain/Foods is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Foods')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Foods builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Foods'))->toBe([]);
});
