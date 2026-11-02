<?php

use Tests\Support\DomainArch;

arch('app/Domain/Placement is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Placement')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Placement builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Placement'))->toBe([]);
});
