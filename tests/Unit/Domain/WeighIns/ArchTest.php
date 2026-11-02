<?php

use Tests\Support\DomainArch;

arch('app/Domain/WeighIns is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\WeighIns')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/WeighIns builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('WeighIns'))->toBe([]);
});
