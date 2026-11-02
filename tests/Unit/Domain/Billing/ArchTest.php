<?php

use Tests\Support\DomainArch;

arch('app/Domain/Billing is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Billing')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Billing builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Billing'))->toBe([]);
});
