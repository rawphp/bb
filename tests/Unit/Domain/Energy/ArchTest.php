<?php

use Tests\Support\DomainArch;

arch('app/Domain/Energy is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Energy')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Energy builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Energy'))->toBe([]);
});
