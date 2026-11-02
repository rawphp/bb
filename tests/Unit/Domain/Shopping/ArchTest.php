<?php

use Tests\Support\DomainArch;

arch('app/Domain/Shopping is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Shopping')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Shopping builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Shopping'))->toBe([]);
});
