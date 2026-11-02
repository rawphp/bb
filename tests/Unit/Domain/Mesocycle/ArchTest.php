<?php

use Tests\Support\DomainArch;

arch('app/Domain/Mesocycle is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Mesocycle')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Mesocycle builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Mesocycle'))->toBe([]);
});
