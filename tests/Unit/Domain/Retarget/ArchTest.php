<?php

use Tests\Support\DomainArch;

arch('app/Domain/Retarget is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Retarget')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Retarget builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Retarget'))->toBe([]);
});
