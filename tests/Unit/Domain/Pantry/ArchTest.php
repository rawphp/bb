<?php

use Tests\Support\DomainArch;

arch('app/Domain/Pantry is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Pantry')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Pantry builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Pantry'))->toBe([]);
});
