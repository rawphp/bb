<?php

use Tests\Support\DomainArch;

arch('app/Domain/Safety is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Safety')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Safety builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Safety'))->toBe([]);
});
