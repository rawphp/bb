<?php

use Tests\Support\DomainArch;

arch('app/Domain/Progress is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Progress')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Progress builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Progress'))->toBe([]);
});
