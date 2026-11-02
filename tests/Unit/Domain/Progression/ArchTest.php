<?php

use Tests\Support\DomainArch;

arch('app/Domain/Progression is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Progression')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Progression builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Progression'))->toBe([]);
});
