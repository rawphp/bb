<?php

use Tests\Support\DomainArch;

arch('app/Domain/Weight is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Weight')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Weight builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Weight'))->toBe([]);
});
