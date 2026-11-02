<?php

use Tests\Support\DomainArch;

arch('app/Domain/Workout is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Workout')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Workout builds no dates: it only moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Workout'))->toBe([]);
});
