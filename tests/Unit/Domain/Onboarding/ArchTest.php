<?php

use Tests\Support\DomainArch;

arch('app/Domain/Onboarding is plain PHP: no Eloquent, models, facades, clock or config')
    ->expect('App\Domain\Onboarding')
    ->not->toUse([...DomainArch::FORBIDDEN_USES, 'App\Models']);

it('app/Domain/Onboarding builds no dates: it only reads and moves the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Onboarding'))->toBe([]);
});
