<?php

use Tests\Support\DomainArch;

arch('app/Domain/Copy is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Copy')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Copy builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Copy'))->toBe([]);
});
